<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Bitrix;

use Bitrix\Main\Event;
use Dimkox\Mcp\Abilities\IblockAbilities;
use Dimkox\Mcp\Abilities\MainAbilities;
use Dimkox\Mcp\Abilities\SaleAbilities;
use Dimkox\Mcp\Ability\AbilityRegistry;

/**
 * Builds the ability registry: built-in abilities, then whatever other modules
 * add through the OnMcpCollectAbilities event.
 *
 * Example handler in another module:
 *
 *     EventManager::getInstance()->addEventHandler('dimkox.mcp', 'OnMcpCollectAbilities',
 *         static function (Event $event): void {
 *             $event->getParameter('registry')->register(Ability::tool([...]));
 *         });
 */
final class AbilityCollector
{
    public const EVENT = 'OnMcpCollectAbilities';

    /** @param bool $applySettings false = every ability, for the settings page */
    public static function collect(bool $applySettings = true): AbilityRegistry
    {
        $registry = new AbilityRegistry();
        MainAbilities::register($registry);
        IblockAbilities::register($registry);
        SaleAbilities::register($registry);

        (new Event(Settings::MODULE_ID, self::EVENT, ['registry' => $registry]))->send();

        if ($applySettings) {
            $registry->enableOnly(Settings::enabledAbilities());
        }
        return $registry;
    }
}
