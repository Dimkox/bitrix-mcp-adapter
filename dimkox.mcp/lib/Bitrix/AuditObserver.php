<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Bitrix;

use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Server\CallObserver;

/**
 * Writes every ability call to the Bitrix event log
 * (Settings → Tools → Event log, type DIMKOX_MCP_CALL).
 */
final class AuditObserver implements CallObserver
{
    public function onCall(string $kind, string $name, AbilityContext $context, string $outcome, float $durationMs, ?string $message): void
    {
        if (!class_exists(\CEventLog::class)) {
            return;
        }
        \CEventLog::Add([
            'SEVERITY' => $outcome === 'ok' ? 'INFO' : 'WARNING',
            'AUDIT_TYPE_ID' => 'DIMKOX_MCP_CALL',
            'MODULE_ID' => Settings::MODULE_ID,
            'ITEM_ID' => $kind . ':' . $name,
            'USER_ID' => $context->userId ?: false,
            'DESCRIPTION' => json_encode([
                'outcome' => $outcome,
                'ms' => round($durationMs, 1),
                'protocol' => $context->protocolVersion,
                'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
}
