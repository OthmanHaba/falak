<?php

namespace Kiln\Sites\Domain;

use InvalidArgumentException;

/**
 * Minimal, strict dotenv parser for the environment editor: KEY=VALUE lines, optional `export `,
 * single/double quotes (double quotes support \n \" \\ \$ escapes), full-line and trailing comments.
 */
final class Dotenv
{
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @return array<string, string> in file order; later duplicates win
     *
     * @throws InvalidArgumentException with the offending line number
     */
    public static function parse(string $contents): array
    {
        $variables = [];
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $position = strpos($trimmed, '=');

            if ($position === false) {
                throw new InvalidArgumentException('Line '.($index + 1).': expected KEY=VALUE.');
            }

            $key = rtrim(substr($trimmed, 0, $position));

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException('Line '.($index + 1).": invalid variable name \"{$key}\".");
            }

            $variables[$key] = self::value(ltrim(substr($trimmed, $position + 1)), $index + 1);
        }

        return $variables;
    }

    private static function value(string $raw, int $line): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote === '"' || $quote === "'") {
            $end = self::closingQuote($raw, $quote);

            if ($end === null) {
                throw new InvalidArgumentException("Line {$line}: unterminated quoted value.");
            }

            $inner = substr($raw, 1, $end - 1);

            return $quote === "'" ? $inner : strtr($inner, ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\', '\\$' => '$']);
        }

        // Unquoted: strip trailing " # comment".
        return rtrim((string) preg_replace('/\s+#.*$/', '', $raw));
    }

    private static function closingQuote(string $raw, string $quote): ?int
    {
        $length = strlen($raw);

        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $raw[$i] === '\\') {
                $i++;

                continue;
            }

            if ($raw[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }
}
