<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Tests;

use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityRegistry;
use Dimkox\Mcp\Schema\SchemaValidator;

final class SchemaValidatorTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'code' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
            'mode' => ['enum' => ['a', 'b']],
            'note' => ['type' => ['string', 'null']],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ];

    public function testValidDocument(): void
    {
        $errors = (new SchemaValidator())->validate(['id' => 3, 'code' => 'abc-1', 'tags' => ['x'], 'mode' => 'a', 'note' => null], self::SCHEMA);
        self::assertSame([], $errors);
    }

    public function testCollectsAllErrors(): void
    {
        $errors = (new SchemaValidator())->validate(['code' => 'ABC', 'tags' => ['x', 'y', 3], 'mode' => 'z', 'more' => 1], self::SCHEMA);
        self::assertSame([
            '$.id is required',
            '$.code does not match pattern ^[a-z0-9-]+$',
            '$.tags must contain at most 2 items',
            '$.tags[2] must be of type string',
            '$.mode must be one of ["a","b"]',
            '$.more is not allowed',
        ], $errors);
    }

    public function testEmptyObjectAndIntegerFloat(): void
    {
        $validator = new SchemaValidator();
        self::assertSame([], $validator->validate([], ['type' => 'object']));
        self::assertSame([], $validator->validate(5.0, ['type' => 'integer']));
        self::assertSame(['$ must be of type integer'], $validator->validate(5.5, ['type' => 'integer']));
    }

    public function testAbilityNameAndResourceUriValidation(): void
    {
        self::assertThrows(\InvalidArgumentException::class, static fn () => Ability::tool([
            'name' => 'has space', 'description' => 'x', 'permission' => 'is_int', 'execute' => 'is_int',
        ]));
        self::assertThrows(\InvalidArgumentException::class, static fn () => Ability::resource([
            'name' => 'r', 'uri' => 'relative/path', 'description' => 'x', 'permission' => 'is_int', 'execute' => 'is_int',
        ]));
        $registry = new AbilityRegistry();
        $tool = Ability::tool(['name' => 'a', 'description' => 'x', 'permission' => 'is_int', 'execute' => 'is_int']);
        $registry->register($tool);
        self::assertThrows(\InvalidArgumentException::class, static fn () => $registry->register($tool));
    }
}
