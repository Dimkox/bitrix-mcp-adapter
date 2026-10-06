<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Http;

use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Protocol\JsonRpc;
use Dimkox\Mcp\Protocol\ProtocolError;
use Dimkox\Mcp\Protocol\VersionNegotiator;
use Dimkox\Mcp\Server\McpServer;

/**
 * MCP Streamable HTTP transport, stateless variant.
 *
 * - POST carries one JSON-RPC message; the reply is a single application/json body.
 * - No session ids and no server-initiated SSE stream: GET and DELETE answer 405.
 * - Every request is authenticated with a bearer token; cookies are never used,
 *   so the endpoint is not exposed to CSRF.
 * - A browser Origin header must name an allowed host (DNS-rebinding protection).
 */
final class HttpHandler
{
    public const MAX_BODY_BYTES = 1_048_576;

    /**
     * @param \Closure(string): ?AbilityContext $authenticate bearer token -> context, null when rejected
     * @param list<string> $allowedOrigins hosts (example.com) or origins (https://example.com)
     */
    public function __construct(
        private readonly McpServer $server,
        private readonly \Closure $authenticate,
        private readonly array $allowedOrigins = [],
        private readonly string $realm = 'bitrix-mcp',
    ) {
    }

    /** @param array<string, string> $headers header names are matched case-insensitively */
    public function handle(string $method, array $headers, string $body): HttpResponse
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $origin = $headers['origin'] ?? '';

        if (!$this->originAllowed($origin)) {
            return HttpResponse::json(403, ['error' => 'origin_not_allowed']);
        }
        $response = $this->dispatch(strtoupper($method), $headers, $body);
        if ($origin === '') {
            return $response;
        }
        // CORS for browser-based clients on an allowed origin.
        $cors = ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];
        if (strtoupper($method) === 'OPTIONS') {
            $cors += [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id, Last-Event-ID',
                'Access-Control-Max-Age' => '600',
            ];
        }
        return new HttpResponse($response->status, $response->headers + $cors, $response->body);
    }

    /** @param array<string, string> $headers lower-cased */
    private function dispatch(string $method, array $headers, string $body): HttpResponse
    {
        if ($method === 'OPTIONS') {
            return new HttpResponse(204, ['Allow' => 'POST, OPTIONS']);
        }
        if ($method !== 'POST') {
            // Stateless server: no standalone SSE stream (GET) and no sessions to end (DELETE).
            return new HttpResponse(405, ['Allow' => 'POST, OPTIONS']);
        }

        $token = self::bearer($headers['authorization'] ?? '');
        $context = $token !== null ? ($this->authenticate)($token) : null;
        if ($context === null) {
            return HttpResponse::json(401, ['error' => 'unauthorized'], [
                'WWW-Authenticate' => sprintf('Bearer realm="%s", error="invalid_token"', $this->realm),
            ]);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return HttpResponse::json(413, JsonRpc::error(null, new ProtocolError(ProtocolError::INVALID_REQUEST, 'Request body too large')));
        }
        $contentType = strtolower($headers['content-type'] ?? 'application/json');
        if (!str_starts_with($contentType, 'application/json')) {
            return HttpResponse::json(415, JsonRpc::error(null, new ProtocolError(ProtocolError::INVALID_REQUEST, 'Content-Type must be application/json')));
        }

        try {
            $message = JsonRpc::decode($body);
        } catch (ProtocolError $e) {
            return HttpResponse::json(400, JsonRpc::error(null, $e));
        }

        $isInitialize = ($message['method'] ?? null) === 'initialize';
        // Without the header the latest revision is assumed (the 2025-03-26 fallback the spec suggests is not offered).
        $version = $headers['mcp-protocol-version'] ?? null;
        if ($version !== null && !$isInitialize && !VersionNegotiator::isSupported($version)) {
            return HttpResponse::json(400, JsonRpc::error(
                is_int($message['id'] ?? null) || is_string($message['id'] ?? null) ? $message['id'] : null,
                new ProtocolError(ProtocolError::INVALID_REQUEST, "Unsupported MCP-Protocol-Version: $version", [
                    'supported' => VersionNegotiator::SUPPORTED,
                ])
            ));
        }
        $context = $context->withProtocolVersion($isInitialize
            ? VersionNegotiator::negotiate($message['params']['protocolVersion'] ?? null)
            : ($version ?? VersionNegotiator::latest()));

        $response = $this->server->handle($message, $context);
        if ($response === null) {
            return new HttpResponse(202);
        }
        return HttpResponse::json(200, $response);
    }

    private static function bearer(string $authorization): ?string
    {
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]{16,512})$/', trim($authorization), $m)) {
            return $m[1];
        }
        return null;
    }

    private function originAllowed(string $origin): bool
    {
        if ($origin === '') {
            return true; // Non-browser clients do not send Origin.
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        $origin = rtrim(strtolower($origin), '/');
        foreach ($this->allowedOrigins as $allowed) {
            $allowed = rtrim(strtolower(trim($allowed)), '/');
            if ($allowed !== '' && ($allowed === $origin || $allowed === strtolower($host))) {
                return true;
            }
        }
        return false;
    }
}
