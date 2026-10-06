<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Tests;

use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Ability\AbilityError;
use Dimkox\Mcp\Ability\AbilityRegistry;

abstract class TestCase
{
    protected static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(trim($message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
        }
    }

    protected static function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    protected static function assertContains(string $needle, string $haystack): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException("'$haystack' does not contain '$needle'");
        }
    }

    protected static function assertThrows(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new \RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        throw new \RuntimeException("expected $class, nothing thrown");
    }

    /** Round-trips through JSON so tests see exactly what goes on the wire. */
    protected static function wire(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    protected static function registry(): AbilityRegistry
    {
        $registry = new AbilityRegistry();
        $registry->register(Ability::tool([
            'name' => 'demo-echo',
            'title' => 'Echo',
            'description' => 'Returns the text it was given',
            'readOnly' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'maxLength' => 20],
                    'times' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'default' => 1],
                ],
                'required' => ['text'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
            ],
            'permission' => static fn (array $args, AbilityContext $ctx): bool => $ctx->userId > 0,
            'execute' => static fn (array $args): array => ['text' => str_repeat($args['text'], $args['times'])],
        ]));
        $registry->register(Ability::tool([
            'name' => 'demo-write',
            'description' => 'Pretends to change data',
            'permission' => static fn (): bool => true,
            'execute' => static fn (): array => ['changed' => true],
        ]));
        $registry->register(Ability::tool([
            'name' => 'demo-fail',
            'description' => 'Always fails',
            'readOnly' => true,
            'permission' => static fn (): bool => true,
            'execute' => static function (array $args): never {
                throw AbilityError::notFound('Element 7');
            },
        ]));
        $registry->register(Ability::tool([
            'name' => 'demo-crash',
            'description' => 'Throws an unexpected exception',
            'readOnly' => true,
            'permission' => static fn (): bool => true,
            'execute' => static function (): never {
                throw new \LogicException('database password is hunter2');
            },
        ]));
        $registry->register(Ability::resource([
            'name' => 'demo-info',
            'uri' => 'bitrix://demo/info',
            'description' => 'Demo resource',
            'permission' => static fn (): bool => true,
            'execute' => static fn (): array => ['site' => 'Демо'],
        ]));
        $registry->register(Ability::prompt([
            'name' => 'demo-prompt',
            'description' => 'Demo prompt',
            'arguments' => [['name' => 'topic', 'description' => 'What to write about', 'required' => true]],
            'permission' => static fn (): bool => true,
            'execute' => static fn (array $args): string => "Напиши о {$args['topic']}",
        ]));
        return $registry;
    }
}
