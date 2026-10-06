<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Abilities;

use Bitrix\Main\UserTable;
use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Ability\AbilityError;
use Dimkox\Mcp\Ability\AbilityRegistry;

/** Abilities of the main module: the current user, users, the site itself. */
final class MainAbilities
{
    public static function register(AbilityRegistry $registry): void
    {
        $registry->register(Ability::tool([
            'name' => 'main-current-user',
            'title' => 'Current user',
            'group' => 'main',
            'description' => 'Returns the Bitrix user this MCP connection acts as: id, login, name, e-mail, groups and whether it is an administrator. Everything the agent does is limited by this user\'s rights.',
            'readOnly' => true,
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'login' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'is_admin' => ['type' => 'boolean'],
                    'groups' => ['type' => 'array'],
                ],
            ],
            'permission' => static fn (array $args, AbilityContext $ctx): bool => $ctx->userId > 0,
            'execute' => static function (array $args, AbilityContext $ctx): array {
                $user = UserTable::getList([
                    'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'EMAIL'],
                    'filter' => ['=ID' => $ctx->userId],
                ])->fetch();
                if (!$user) {
                    throw AbilityError::notFound('User');
                }
                $groups = [];
                $rows = \Bitrix\Main\GroupTable::getList([
                    'select' => ['ID', 'NAME'],
                    'filter' => ['@ID' => array_map('intval', \CUser::GetUserGroup($ctx->userId))],
                    'order' => ['C_SORT' => 'ASC'],
                ]);
                while ($group = $rows->fetch()) {
                    $groups[] = ['id' => (int) $group['ID'], 'name' => (string) $group['NAME']];
                }
                return [
                    'id' => (int) $user['ID'],
                    'login' => (string) $user['LOGIN'],
                    'name' => trim($user['NAME'] . ' ' . $user['LAST_NAME']),
                    'email' => (string) $user['EMAIL'],
                    'is_admin' => Access::isAdmin(),
                    'groups' => $groups,
                ];
            },
        ]));

        $registry->register(Ability::tool([
            'name' => 'main-search-users',
            'title' => 'Search users',
            'group' => 'main',
            'description' => 'Finds site users by login, name or e-mail. Returns personal data; requires the "view all users" right.',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'description' => 'Part of a login, name, last name or e-mail'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'permission' => static fn (): bool => Access::canDo('view_all_users'),
            'execute' => static function (array $args, AbilityContext $ctx): array {
                $query = $args['query'];
                $rows = UserTable::getList([
                    'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'EMAIL', 'ACTIVE', 'LAST_LOGIN'],
                    'filter' => [[
                        'LOGIC' => 'OR',
                        '%LOGIN' => $query,
                        '%NAME' => $query,
                        '%LAST_NAME' => $query,
                        '%EMAIL' => $query,
                    ]],
                    'order' => ['ID' => 'ASC'],
                    'limit' => min($args['limit'], $ctx->maxPageSize),
                ]);
                $users = [];
                while ($row = $rows->fetch()) {
                    $users[] = [
                        'id' => (int) $row['ID'],
                        'login' => (string) $row['LOGIN'],
                        'name' => trim($row['NAME'] . ' ' . $row['LAST_NAME']),
                        'email' => (string) $row['EMAIL'],
                        'active' => $row['ACTIVE'] === 'Y',
                        'last_login' => Access::date($row['LAST_LOGIN']),
                    ];
                }
                return ['users' => $users];
            },
        ]));

        $registry->register(Ability::resource([
            'name' => 'site-info',
            'uri' => 'bitrix://site/info',
            'title' => 'Site information',
            'group' => 'main',
            'description' => 'Sites of this installation (id, name, domain, language), the main module version and, for administrators, the installed modules.',
            'permission' => static fn (array $args, AbilityContext $ctx): bool => $ctx->userId > 0,
            'execute' => static function (): array {
                $sites = [];
                $rows = \Bitrix\Main\SiteTable::getList([
                    'select' => ['LID', 'NAME', 'SERVER_NAME', 'DIR', 'LANGUAGE_ID', 'DEF'],
                    'filter' => ['=ACTIVE' => 'Y'],
                    'order' => ['SORT' => 'ASC'],
                ]);
                while ($site = $rows->fetch()) {
                    $sites[] = [
                        'id' => (string) $site['LID'],
                        'name' => (string) $site['NAME'],
                        'server_name' => (string) $site['SERVER_NAME'],
                        'dir' => (string) $site['DIR'],
                        'language' => (string) $site['LANGUAGE_ID'],
                        'default' => $site['DEF'] === 'Y',
                    ];
                }
                $info = [
                    'site_name' => (string) \Bitrix\Main\Config\Option::get('main', 'site_name', ''),
                    'main_version' => defined('SM_VERSION') ? SM_VERSION : null,
                    'sites' => $sites,
                ];
                if (Access::isAdmin()) {
                    $info['modules'] = array_keys(\Bitrix\Main\ModuleManager::getInstalledModules());
                }
                return $info;
            },
        ]));
    }
}
