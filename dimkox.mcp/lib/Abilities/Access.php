<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Abilities;

/**
 * Permission helpers over the current Bitrix user. The endpoint authorizes the
 * token's user for the request, so global $USER and module rights are that user's.
 */
final class Access
{
    public static function user(): ?\CUser
    {
        $user = $GLOBALS['USER'] ?? null;
        return $user instanceof \CUser ? $user : null;
    }

    public static function isAuthorized(): bool
    {
        return (bool) self::user()?->IsAuthorized();
    }

    public static function isAdmin(): bool
    {
        return (bool) self::user()?->IsAdmin();
    }

    public static function canDo(string $operation): bool
    {
        return self::isAdmin() || (bool) self::user()?->CanDoOperation($operation);
    }

    /** Module right letter for the current user: D (deny) < R < ... < W (write) < X. */
    public static function moduleRight(string $moduleId): string
    {
        global $APPLICATION;
        return $APPLICATION instanceof \CMain ? (string) $APPLICATION->GetGroupRight($moduleId) : 'D';
    }

    /** ISO 8601 for Bitrix dates and datetimes; null stays null. */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof \Bitrix\Main\Type\Date) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_string($value) && $value !== '') {
            $timestamp = MakeTimeStamp($value);
            return $timestamp ? date(\DateTimeInterface::ATOM, $timestamp) : $value;
        }
        return null;
    }

    /** Plain text from HTML, bounded for the model's context. */
    public static function text(?string $html, int $limit = 4000): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
