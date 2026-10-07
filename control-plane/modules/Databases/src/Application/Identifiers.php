<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Enums\Engine;
use Illuminate\Validation\ValidationException;

/**
 * Database / user / instance name rules shared by every action (mirrors the agent schemas' identifier pattern).
 */
final class Identifiers
{
    public const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    /** Instance names: the canvas service's name, part of the volume's name. */
    public const INSTANCE_PATTERN = '/^[a-z][a-z0-9_-]{0,40}$/';

    /**
     * @throws ValidationException
     */
    public static function assertInstanceName(string $name, string $field = 'name'): void
    {
        if (preg_match(self::INSTANCE_PATTERN, $name) !== 1) {
            throw ValidationException::withMessages([$field => 'The name must start with a lowercase letter and contain only lowercase letters, digits, dashes and underscores (max 41).']);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertValid(Engine $engine, string $name, string $field, string $noun = 'name'): void
    {
        if ($engine->isKeyValue()) {
            self::assertInstanceName($name, $field);

            return;
        }

        if (preg_match(self::PATTERN, $name) !== 1) {
            throw ValidationException::withMessages([$field => "The {$noun} must start with a letter or underscore and contain only letters, digits and underscores (max 63)."]);
        }

        if (in_array(strtolower($name), $engine->reservedNames(), true) || str_starts_with(strtolower($name), 'pg_')) {
            throw ValidationException::withMessages([$field => "\"{$name}\" is reserved by {$engine->label()}."]);
        }
    }

    /** A SQL identifier derived from an instance name ("shop-db" → "shop_db"). */
    public static function sqlName(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);

        return substr(preg_match('/^[A-Za-z_]/', $name) === 1 ? $name : "db_{$name}", 0, 58);
    }
}
