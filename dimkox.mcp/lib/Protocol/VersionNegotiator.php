<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Protocol;

/**
 * MCP protocol revision negotiation.
 *
 * The server answers `initialize` with the client's requested revision when it
 * supports it, and otherwise with its newest revision (the client then decides
 * whether to continue). 2025-03-26 is not offered: that revision requires
 * servers to accept JSON-RPC batches, which this server rejects.
 */
final class VersionNegotiator
{
    /** Newest first. */
    public const SUPPORTED = ['2025-11-25', '2025-06-18'];

    public static function latest(): string
    {
        return self::SUPPORTED[0];
    }

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    public static function negotiate(mixed $requested): string
    {
        return is_string($requested) && self::isSupported($requested) ? $requested : self::latest();
    }

    /** `structuredContent` and `outputSchema` exist since 2025-06-18, which is the oldest revision served. */
    public static function supportsStructuredContent(string $version): bool
    {
        return self::isSupported($version);
    }
}
