<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Bitrix;

use Bitrix\Main\Config\Option;

/**
 * Module options. Everything is off until the administrator turns it on.
 */
final class Settings
{
    public const MODULE_ID = 'dimkox.mcp';

    /** Read-only abilities without personal data, pre-selected on first enable. */
    public const DEFAULT_ABILITIES = [
        'main-current-user',
        'iblock-list-iblocks',
        'iblock-list-sections',
        'iblock-search-elements',
        'iblock-get-element',
        'site-info',
    ];

    public static function isEnabled(): bool
    {
        return Option::get(self::MODULE_ID, 'enabled', 'N') === 'Y';
    }

    public static function allowWrite(): bool
    {
        return Option::get(self::MODULE_ID, 'allow_write', 'N') === 'Y';
    }

    public static function auditEnabled(): bool
    {
        return Option::get(self::MODULE_ID, 'audit', 'Y') === 'Y';
    }

    public static function maxPageSize(): int
    {
        return max(1, min(200, (int) Option::get(self::MODULE_ID, 'max_page_size', '50')));
    }

    /** @return list<string> */
    public static function enabledAbilities(): array
    {
        $raw = Option::get(self::MODULE_ID, 'abilities', implode(',', self::DEFAULT_ABILITIES));
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /** @param list<string> $names */
    public static function setEnabledAbilities(array $names): void
    {
        Option::set(self::MODULE_ID, 'abilities', implode(',', array_unique($names)));
    }

    /**
     * Origins allowed to call from a browser: the sites' configured domains plus the extra list.
     *
     * @return list<string>
     */
    public static function allowedOrigins(): array
    {
        $configured = preg_split('/[\s,]+/', Option::get(self::MODULE_ID, 'allowed_origins', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Never the request's Host header: under DNS rebinding Host and Origin are both the attacker's.
        $own = [];
        $serverName = (string) Option::get('main', 'server_name', '');
        if ($serverName !== '') {
            $own[] = $serverName;
        }
        $sites = \Bitrix\Main\SiteTable::getList(['select' => ['SERVER_NAME'], 'filter' => ['=ACTIVE' => 'Y']]);
        while ($site = $sites->fetch()) {
            if ((string) $site['SERVER_NAME'] !== '') {
                $own[] = (string) $site['SERVER_NAME'];
            }
        }
        return array_values(array_unique([...$own, ...$configured]));
    }

    /**
     * Property codes never shown to agents (purchase price, manager notes...).
     *
     * @return list<string>
     */
    public static function hiddenProperties(): array
    {
        return preg_split('/[\s,]+/', strtoupper(Option::get(self::MODULE_ID, 'hidden_properties', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function instructions(): string
    {
        return (string) Option::get(self::MODULE_ID, 'instructions', '');
    }
}
