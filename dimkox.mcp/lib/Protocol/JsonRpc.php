<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Protocol;

/**
 * JSON-RPC 2.0 decoding and envelope helpers.
 *
 * Batches are rejected: MCP removed JSON-RPC batching in revision 2025-06-18.
 */
final class JsonRpc
{
    public const MAX_DEPTH = 64;

    /**
     * @return array<string, mixed> the decoded message
     * @throws ProtocolError
     */
    public static function decode(string $body): array
    {
        if (trim($body) === '') {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'Empty request body');
        }
        try {
            $message = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ProtocolError(ProtocolError::PARSE_ERROR, 'Parse error: ' . $e->getMessage());
        }
        if (!is_array($message)) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'A JSON-RPC message must be an object');
        }
        if (array_is_list($message) && $message !== []) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'JSON-RPC batches are not supported');
        }
        if (($message['jsonrpc'] ?? null) !== '2.0') {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'The "jsonrpc" member must be "2.0"');
        }
        return $message;
    }

    /** A request carries an id and a method; a notification has a method and no id. */
    public static function isRequest(array $message): bool
    {
        return array_key_exists('id', $message) && isset($message['method']);
    }

    public static function isNotification(array $message): bool
    {
        return !array_key_exists('id', $message) && isset($message['method']);
    }

    /** A response sent by the client (to a server request); the stateless server ignores these. */
    public static function isResponse(array $message): bool
    {
        return array_key_exists('id', $message) && !isset($message['method'])
            && (array_key_exists('result', $message) || array_key_exists('error', $message));
    }

    /** @throws ProtocolError */
    public static function validateId(mixed $id): string|int
    {
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return $id;
        }
        throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'The request id must be a non-empty string or an integer');
    }

    /** @return array<string, mixed> */
    public static function result(string|int $id, array|object $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public static function error(string|int|null $id, ProtocolError $error): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error->toArray()];
    }

    public static function encode(array $message): string
    {
        return json_encode(
            $message,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
