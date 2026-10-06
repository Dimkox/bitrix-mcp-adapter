<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Server;

use Dimkox\Mcp\Ability\AbilityContext;

/** Receives one record per ability invocation (audit log, metrics). */
interface CallObserver
{
    /**
     * @param string $outcome "ok", "error" (ability reported a failure), "denied" or "invalid"
     */
    public function onCall(string $kind, string $name, AbilityContext $context, string $outcome, float $durationMs, ?string $message): void;
}
