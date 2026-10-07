<?php

namespace Falak\Databases\Application;

/**
 * A restore drill's check query: one SELECT (or WITH … SELECT), no statement separator, comment or backslash. The agent's
 * `falak-db query` applies the same rules and runs it as `SELECT count(*) FROM (<query>)` in a read-only transaction
 * on the throwaway instance; the drill fails when it returns no row.
 */
final class DrillQuery
{
    public const MAX = 4000;

    /** Null when valid, else why not. */
    public static function problem(string $sql): ?string
    {
        $query = trim(rtrim(trim($sql), ';'));

        return match (true) {
            $query === '' => 'Enter a SELECT query.',
            strlen($query) > self::MAX => 'The check query is longer than '.self::MAX.' characters.',
            preg_match('/[;\\\\\x00]/', $query) === 1 => 'Use a single statement without ";" or "\".',
            str_contains($query, '--') || str_contains($query, '/*') || str_contains($query, '#') => 'Remove the comments (--, /*, #).',
            preg_match('/^(select|with)[\s(]/i', $query) !== 1 => 'The check query must be a SELECT (it runs read-only).',
            default => null,
        };
    }

    public static function normalize(string $sql): string
    {
        return trim(rtrim(trim($sql), ';'));
    }
}
