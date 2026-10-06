<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Ability;

/**
 * Thrown by an ability to report a failure the agent should see and may react
 * to (bad input, not found, access denied). It becomes a tool result with
 * isError=true rather than a protocol error.
 */
final class AbilityError extends \RuntimeException
{
    public static function notFound(string $what): self
    {
        return new self("$what not found");
    }

    public static function accessDenied(string $detail = 'Access denied'): self
    {
        return new self($detail);
    }
}
