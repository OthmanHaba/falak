<?php

namespace Falak\Databases\Application;

use Illuminate\Validation\ValidationException;
use Falak\Databases\Domain\Enums\Engine;

/**
 * Database / user name rules shared by every action (mirrors the agent schemas' identifier pattern).
 */
final class Identifiers
{
    public const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    /** Redis / Valkey instance names (db.redis.* schemas): part of a systemd unit and file names. */
    public const KEY_VALUE_PATTERN = '/^[a-z][a-z0-9_-]{0,40}$/';

    /**
     * @throws ValidationException
     */
    public static function assertValid(Engine $engine, string $name, string $field, string $noun = 'name'): void
    {
        if ($engine->isKeyValue()) {
            if (preg_match(self::KEY_VALUE_PATTERN, $name) !== 1) {
                throw ValidationException::withMessages([$field => "The {$noun} must start with a lowercase letter and contain only lowercase letters, digits, dashes and underscores (max 41)."]);
            }

            if (in_array($name, $engine->reservedNames(), true)) {
                throw ValidationException::withMessages([$field => "\"{$name}\" is reserved."]);
            }

            return;
        }

        if (preg_match(self::PATTERN, $name) !== 1) {
            throw ValidationException::withMessages([$field => "The {$noun} must start with a letter or underscore and contain only letters, digits and underscores (max 63)."]);
        }

        if (in_array(strtolower($name), $engine->reservedNames(), true) || str_starts_with(strtolower($name), 'pg_')) {
            throw ValidationException::withMessages([$field => "\"{$name}\" is reserved by {$engine->label()}."]);
        }
    }
}
