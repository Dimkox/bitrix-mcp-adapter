<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Bitrix;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Issues, resolves and revokes MCP access tokens.
 *
 * A token acts as the Bitrix user it was issued for: the agent sees and may
 * change exactly what that user may see and change.
 */
final class TokenService
{
    public const PREFIX = 'bxmcp_';

    /** @return array{id: int, token: string} the plain token is returned only here */
    public static function issue(int $userId, string $name, ?DateTime $expiresAt = null): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A token must belong to a user');
        }
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $result = TokenTable::add([
            'USER_ID' => $userId,
            'NAME' => mb_substr(trim($name) !== '' ? trim($name) : 'MCP client', 0, 100),
            'TOKEN_HASH' => self::hash($token),
            'TOKEN_PREFIX' => substr($token, 0, 12),
            'EXPIRES_AT' => $expiresAt,
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
        return ['id' => (int) $result->getId(), 'token' => $token];
    }

    /** Returns the user id for a valid token, or null. Also records the last use. */
    public static function resolve(string $token): ?int
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) !== strlen(self::PREFIX) + 64) {
            return null;
        }
        $row = TokenTable::getList([
            'select' => ['ID', 'USER_ID', 'ACTIVE', 'EXPIRES_AT', 'TOKEN_HASH'],
            'filter' => ['=TOKEN_HASH' => self::hash($token)],
            'limit' => 1,
        ])->fetch();
        $active = $row && ($row['ACTIVE'] === true || $row['ACTIVE'] === 'Y');
        if (!$active || !hash_equals((string) $row['TOKEN_HASH'], self::hash($token))) {
            return null;
        }
        if ($row['EXPIRES_AT'] instanceof DateTime && $row['EXPIRES_AT']->getTimestamp() < time()) {
            return null;
        }
        $user = UserTable::getList([
            'select' => ['ID'],
            'filter' => ['=ID' => (int) $row['USER_ID'], '=ACTIVE' => 'Y'],
            'limit' => 1,
        ])->fetch();
        if (!$user) {
            return null;
        }
        TokenTable::update($row['ID'], ['LAST_USED_AT' => new DateTime()]);
        return (int) $row['USER_ID'];
    }

    public static function revoke(int $id): void
    {
        TokenTable::update($id, ['ACTIVE' => 'N']);
    }

    public static function delete(int $id): void
    {
        TokenTable::delete($id);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
