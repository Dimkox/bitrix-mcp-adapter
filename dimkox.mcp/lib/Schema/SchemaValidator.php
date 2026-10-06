<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Schema;

/**
 * Validates decoded JSON (PHP arrays/scalars) against the subset of JSON Schema
 * used by ability input schemas:
 * type (incl. type lists), properties, required, additionalProperties (bool),
 * enum, const, minLength, maxLength, pattern, minimum, maximum, items,
 * minItems, maxItems, default.
 *
 * Unknown keywords are ignored, so richer schemas still work for clients.
 */
final class SchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @return list<string> human-readable errors; empty when valid
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "$path must equal " . json_encode($schema['const']);
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "$path must be one of " . json_encode($schema['enum'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = null;
            foreach ($types as $type) {
                if (self::isType($value, (string) $type)) {
                    $matched = (string) $type;
                    break;
                }
            }
            if ($matched === null) {
                return [...$errors, "$path must be of type " . implode('|', $types)];
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = "$path must be at least {$schema['minLength']} characters";
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = "$path must be at most {$schema['maxLength']} characters";
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $regex = '/' . str_replace('/', '\/', $schema['pattern']) . '/u';
                if (@preg_match($regex, $value) !== 1) {
                    $errors[] = "$path does not match pattern {$schema['pattern']}";
                }
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "$path must be >= {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "$path must be <= {$schema['maximum']}";
            }
        }

        if (is_array($value) && array_is_list($value) && self::declares($schema, 'array')) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "$path must contain at least {$schema['minItems']} items";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "$path must contain at most {$schema['maxItems']} items";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    $errors = [...$errors, ...$this->validate($item, $schema['items'], "{$path}[$i]")];
                }
            }
        } elseif (is_array($value) && self::declares($schema, 'object')) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (!array_key_exists($name, $value)) {
                    $errors[] = "$path.$name is required";
                }
            }
            foreach ($value as $name => $item) {
                if (isset($properties[$name]) && is_array($properties[$name])) {
                    $errors = [...$errors, ...$this->validate($item, $properties[$name], "$path.$name")];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "$path.$name is not allowed";
                }
            }
        }

        return $errors;
    }

    /**
     * Fills in top-level defaults declared by an object schema.
     *
     * @param array<string, mixed> $value
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function applyDefaults(array $value, array $schema): array
    {
        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            if (!array_key_exists($name, $value) && is_array($property) && array_key_exists('default', $property)) {
                $value[$name] = $property['default'];
            }
        }
        return $value;
    }

    private static function declares(array $schema, string $type): bool
    {
        return !isset($schema['type']) || in_array($type, (array) $schema['type'], true);
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            // JSON {} decodes to [] in PHP: an empty array is accepted as an object.
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            default => true,
        };
    }
}
