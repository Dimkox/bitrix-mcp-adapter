<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Ability;

/**
 * Holds every registered ability. Registration is open (other modules add
 * abilities through the OnMcpCollectAbilities event); exposure is not: only
 * names in the enabled list are listed or callable.
 */
final class AbilityRegistry
{
    /** @var array<string, Ability> */
    private array $abilities = [];
    /** @var array<string, true>|null null = every ability enabled (tests, CLI tooling) */
    private ?array $enabled = null;

    public function register(Ability $ability): void
    {
        if (isset($this->abilities[$ability->name])) {
            throw new \InvalidArgumentException("Ability \"{$ability->name}\" is already registered");
        }
        if ($ability->uri !== null) {
            foreach ($this->abilities as $other) {
                if ($other->uri === $ability->uri) {
                    throw new \InvalidArgumentException("Resource URI \"{$ability->uri}\" is already registered by \"{$other->name}\"");
                }
            }
        }
        $this->abilities[$ability->name] = $ability;
    }

    /** @param list<string> $names */
    public function enableOnly(array $names): void
    {
        $this->enabled = array_fill_keys($names, true);
    }

    /** Every registered ability, enabled or not (for the settings page). */
    public function all(): array
    {
        $all = $this->abilities;
        ksort($all);
        return array_values($all);
    }

    /** @return list<Ability> exposed abilities of one kind, sorted by name */
    public function exposed(string $kind): array
    {
        $result = [];
        foreach ($this->all() as $ability) {
            if ($ability->kind === $kind && $this->isExposed($ability)) {
                $result[] = $ability;
            }
        }
        return $result;
    }

    public function find(string $kind, string $name): ?Ability
    {
        $ability = $this->abilities[$name] ?? null;
        return $ability !== null && $ability->kind === $kind && $this->isExposed($ability) ? $ability : null;
    }

    public function findResource(string $uri): ?Ability
    {
        foreach ($this->exposed(Ability::RESOURCE) as $ability) {
            if ($ability->uri === $uri) {
                return $ability;
            }
        }
        return null;
    }

    private function isExposed(Ability $ability): bool
    {
        return ($this->enabled === null || isset($this->enabled[$ability->name])) && $ability->isAvailable();
    }
}
