<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Ability;

/**
 * Who is calling and how. Abilities use it for permission checks and limits.
 */
final class AbilityContext
{
    /**
     * @param int $userId Bitrix user the request is authenticated as (0 = anonymous)
     * @param bool $allowWrite site-wide switch: abilities that change data are refused when false
     * @param int $maxPageSize upper bound for list abilities
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $allowWrite = false,
        public readonly int $maxPageSize = 50,
        public readonly string $protocolVersion = '',
    ) {
    }

    public function withProtocolVersion(string $version): self
    {
        return new self($this->userId, $this->allowWrite, $this->maxPageSize, $version);
    }
}
