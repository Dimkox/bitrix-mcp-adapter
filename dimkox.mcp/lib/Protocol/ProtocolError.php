<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Protocol;

/**
 * A JSON-RPC level error (the request itself is wrong), as opposed to a tool
 * execution error, which is reported inside a successful result with isError=true.
 */
final class ProtocolError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    /** MCP: resource not found. */
    public const RESOURCE_NOT_FOUND = -32002;

    /** @param array<string, mixed>|null $data */
    public function __construct(int $code, string $message, private readonly ?array $data = null)
    {
        parent::__construct($message, $code);
    }

    /** @return array<string, mixed>|null */
    public function getData(): ?array
    {
        return $this->data;
    }

    /** @return array{code: int, message: string, data?: array<string, mixed>} */
    public function toArray(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }
        return $error;
    }
}
