<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Ability;

/**
 * One capability of the site that an AI agent may use: a tool (an action with
 * typed input), a resource (readable content addressed by URI) or a prompt
 * (a ready-made message template).
 *
 * An ability is never exposed just because it is registered: the site
 * administrator enables abilities one by one in the module settings.
 */
final class Ability
{
    public const TOOL = 'tool';
    public const RESOURCE = 'resource';
    public const PROMPT = 'prompt';

    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

    /** @var \Closure(array<string, mixed>, AbilityContext): bool */
    private \Closure $permission;
    /** @var \Closure(array<string, mixed>, AbilityContext): mixed */
    private \Closure $execute;
    /** @var \Closure(): bool */
    private \Closure $available;

    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed>|null $outputSchema
     * @param list<array{name: string, description?: string, required?: bool}> $arguments prompt arguments
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly ?array $outputSchema,
        public readonly bool $readOnly,
        public readonly bool $destructive,
        public readonly bool $idempotent,
        public readonly ?string $uri,
        public readonly string $mimeType,
        public readonly array $arguments,
        public readonly string $group,
        callable $permission,
        callable $execute,
        ?callable $available,
    ) {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException("Invalid ability name \"$name\": use letters, digits, '_', '-', '.' (max 128)");
        }
        if ($kind === self::RESOURCE && ($uri === null || !preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri))) {
            throw new \InvalidArgumentException("Resource ability \"$name\" needs an absolute URI");
        }
        $this->permission = \Closure::fromCallable($permission);
        $this->execute = \Closure::fromCallable($execute);
        $this->available = $available !== null ? \Closure::fromCallable($available) : static fn (): bool => true;
    }

    /**
     * @param array{
     *   name: string, title?: string, description: string,
     *   inputSchema?: array<string, mixed>, outputSchema?: array<string, mixed>,
     *   readOnly?: bool, destructive?: bool, idempotent?: bool, group?: string,
     *   permission: callable, execute: callable, available?: callable
     * } $spec
     */
    public static function tool(array $spec): self
    {
        $readOnly = (bool) ($spec['readOnly'] ?? false);
        return new self(
            self::TOOL,
            $spec['name'],
            $spec['title'] ?? $spec['name'],
            $spec['description'],
            self::normalizeObjectSchema($spec['inputSchema'] ?? []),
            isset($spec['outputSchema']) ? self::normalizeObjectSchema($spec['outputSchema']) : null,
            $readOnly,
            $readOnly ? false : (bool) ($spec['destructive'] ?? true),
            $readOnly ? true : (bool) ($spec['idempotent'] ?? false),
            null,
            'application/json',
            [],
            $spec['group'] ?? 'custom',
            $spec['permission'],
            $spec['execute'],
            $spec['available'] ?? null,
        );
    }

    /**
     * @param array{
     *   name: string, uri: string, title?: string, description: string, mimeType?: string, group?: string,
     *   permission: callable, execute: callable, available?: callable
     * } $spec execute returns the resource text (string) or JSON-serializable data
     */
    public static function resource(array $spec): self
    {
        return new self(
            self::RESOURCE,
            $spec['name'],
            $spec['title'] ?? $spec['name'],
            $spec['description'],
            self::normalizeObjectSchema([]),
            null,
            true,
            false,
            true,
            $spec['uri'],
            $spec['mimeType'] ?? 'application/json',
            [],
            $spec['group'] ?? 'custom',
            $spec['permission'],
            $spec['execute'],
            $spec['available'] ?? null,
        );
    }

    /**
     * @param array{
     *   name: string, title?: string, description: string, group?: string,
     *   arguments?: list<array{name: string, description?: string, required?: bool}>,
     *   permission: callable, execute: callable, available?: callable
     * } $spec execute returns the prompt text (string) or a list of MCP prompt messages
     */
    public static function prompt(array $spec): self
    {
        $arguments = $spec['arguments'] ?? [];
        $properties = [];
        $required = [];
        foreach ($arguments as $argument) {
            $properties[$argument['name']] = ['type' => 'string', 'description' => $argument['description'] ?? ''];
            if (!empty($argument['required'])) {
                $required[] = $argument['name'];
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return new self(
            self::PROMPT,
            $spec['name'],
            $spec['title'] ?? $spec['name'],
            $spec['description'],
            self::normalizeObjectSchema($schema),
            null,
            true,
            false,
            true,
            null,
            'text/plain',
            $arguments,
            $spec['group'] ?? 'custom',
            $spec['permission'],
            $spec['execute'],
            $spec['available'] ?? null,
        );
    }

    /** Changes site data: refused unless the administrator allowed write abilities. */
    public function writes(): bool
    {
        return !$this->readOnly;
    }

    public function isAvailable(): bool
    {
        return (bool) ($this->available)();
    }

    /** @param array<string, mixed> $arguments */
    public function isPermitted(array $arguments, AbilityContext $context): bool
    {
        return (bool) ($this->permission)($arguments, $context);
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments, AbilityContext $context): mixed
    {
        return ($this->execute)($arguments, $context);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalizeObjectSchema(array $schema): array
    {
        $schema['type'] ??= 'object';
        if (!isset($schema['properties']) || $schema['properties'] === []) {
            // Serializes as {} rather than [].
            $schema['properties'] = new \stdClass();
        }
        return $schema;
    }
}
