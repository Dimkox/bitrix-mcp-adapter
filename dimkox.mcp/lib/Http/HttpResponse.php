<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function json(int $status, array $payload, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
        );
    }
}
