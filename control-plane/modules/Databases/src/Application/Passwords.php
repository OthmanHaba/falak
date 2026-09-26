<?php

namespace Kiln\Databases\Application;

/**
 * Database passwords: long, random and safe to paste into DSNs, .env files and shell commands
 * without quoting (letters and digits only; entropy comes from length).
 */
final class Passwords
{
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(?int $length = null): string
    {
        $length = max(16, $length ?? (int) config('databases.password_length', 32));
        $max = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
