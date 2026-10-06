<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Bitrix;

use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Http\HttpHandler;
use Dimkox\Mcp\Http\HttpResponse;
use Dimkox\Mcp\Server\McpServer;

/**
 * Wires the MCP server into a Bitrix request: settings, tokens, user context, audit.
 * Used by /bitrix/tools/dimkox.mcp/index.php (HTTP) and cli/stdio.php (STDIO).
 */
final class Endpoint
{
    public static function run(): void
    {
        if (!Settings::isEnabled()) {
            self::emit(HttpResponse::json(503, ['error' => 'mcp_disabled', 'message' => 'The MCP server is turned off in the dimkox.mcp module settings']));
            return;
        }
        $handler = new HttpHandler(
            self::server(),
            \Closure::fromCallable([self::class, 'authenticate']),
            Settings::allowedOrigins(),
        );
        self::emit($handler->handle(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::headers(),
            (string) file_get_contents('php://input'),
        ));
    }

    public static function server(): McpServer
    {
        return new McpServer(
            AbilityCollector::collect(),
            'bitrix-mcp',
            self::moduleVersion(),
            (string) (\Bitrix\Main\Config\Option::get('main', 'site_name', '') ?: '1C-Bitrix') . ' (MCP)',
            Settings::instructions(),
            Settings::auditEnabled() ? new AuditObserver() : null,
        );
    }

    /** Resolves a token and makes its user the current Bitrix user for this request. */
    public static function authenticate(string $token): ?AbilityContext
    {
        $userId = TokenService::resolve($token);
        if ($userId === null) {
            return null;
        }
        global $USER;
        if (!$USER instanceof \CUser) {
            $USER = new \CUser();
        }
        if (!$USER->Authorize($userId, false, false)) {
            return null;
        }
        return new AbilityContext($userId, Settings::allowWrite(), Settings::maxPageSize());
    }

    public static function moduleVersion(): string
    {
        $arModuleVersion = [];
        include dirname(__DIR__, 2) . '/install/version.php';
        return (string) ($arModuleVersion['VERSION'] ?? '0.0.0');
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        // Apache with CGI/FastCGI drops Authorization unless rewritten into the environment.
        $headers['AUTHORIZATION'] ??= (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return $headers;
    }

    private static function emit(HttpResponse $response): void
    {
        global $APPLICATION;
        if ($APPLICATION instanceof \CMain) {
            $APPLICATION->RestartBuffer();
        }
        http_response_code($response->status);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($response->headers as $name => $value) {
            header("$name: $value");
        }
        echo $response->body;
    }
}
