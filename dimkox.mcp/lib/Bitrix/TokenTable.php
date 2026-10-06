<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Bitrix;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Access tokens for MCP clients. Only a SHA-256 hash of the token is stored;
 * the token itself is shown once, when it is issued.
 */
final class TokenTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_dimkox_mcp_token';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new IntegerField('USER_ID'))->configureRequired(),
            (new StringField('NAME'))->configureRequired()->configureSize(100),
            (new StringField('TOKEN_HASH'))->configureRequired()->configureSize(64),
            (new StringField('TOKEN_PREFIX'))->configureSize(16),
            (new BooleanField('ACTIVE'))->configureValues('N', 'Y')->configureDefaultValue('Y'),
            (new DatetimeField('CREATED_AT'))->configureDefaultValue(static fn () => new DateTime()),
            (new DatetimeField('LAST_USED_AT'))->configureNullable(),
            (new DatetimeField('EXPIRES_AT'))->configureNullable(),
        ];
    }
}
