<?php

namespace Falak\Insights\Domain\Support;

/**
 * Parses PHP (`#0 /file.php(12): Foo->bar()`) and V8/Node (`    at fn (/file.js:1:2)`) stack traces.
 * Anything else becomes a frame per line with no file information.
 */
final class StackTrace
{
    private const PHP_FRAME = '/^#\d+\s+(?:(?<file>.+?)\((?<line>\d+)\)|\[internal function\]):\s*(?<function>.*)$/';

    private const NODE_FRAME_CALL = '/^at\s+(?<function>.+?)\s+\((?<location>.+)\)$/';

    private const NODE_FRAME_BARE = '/^at\s+(?<location>.+)$/';

    /**
     * @return list<StackFrame>
     */
    public static function parse(?string $stacktrace): array
    {
        if ($stacktrace === null || trim($stacktrace) === '') {
            return [];
        }

        $frames = [];

        foreach (preg_split('/\r?\n/', $stacktrace) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line === 'Stack trace:' || preg_match('/^#\d+\s+\{main\}$/', $line) === 1) {
                continue;
            }

            if (preg_match(self::PHP_FRAME, $line, $m) === 1) {
                $file = isset($m['file']) && $m['file'] !== '' ? self::normalizeFile($m['file']) : null;
                $frames[] = new StackFrame($line, $file, isset($m['line']) && $m['line'] !== '' ? (int) $m['line'] : null, self::normalizeFunction($m['function']), self::inApp($file, $m['file'] ?? null));

                continue;
            }

            if (str_starts_with($line, 'at ')) {
                if (preg_match(self::NODE_FRAME_CALL, $line, $m) === 1) {
                    [$file, $lineNo] = self::nodeLocation($m['location']);
                    $frames[] = new StackFrame($line, $file, $lineNo, self::normalizeFunction($m['function']), self::inApp($file, $m['location']));

                    continue;
                }

                if (preg_match(self::NODE_FRAME_BARE, $line, $m) === 1) {
                    [$file, $lineNo] = self::nodeLocation($m['location']);
                    $frames[] = new StackFrame($line, $file, $lineNo, null, self::inApp($file, $m['location']));

                    continue;
                }
            }

            // Header lines of V8 traces ("TypeError: x is undefined") are not frames.
            if ($frames === [] && preg_match('/^[\w.$\\\\]+(?:Error|Exception)?:\s/', $line) === 1) {
                continue;
            }

            $frames[] = new StackFrame($line, null, null, null, false);
        }

        return $frames;
    }

    /**
     * Strip deployment-specific prefixes so frames match across releases:
     * /srv/falak/sites/shop/releases/01J…/app/Foo.php → app/Foo.php
     */
    public static function normalizeFile(string $file): string
    {
        $file = preg_replace('#^file://#', '', trim($file)) ?? $file;
        $file = preg_replace('#^.*?/(?:releases/[^/]+|current)/#', '', $file) ?? $file;

        return $file;
    }

    private static function normalizeFunction(string $function): ?string
    {
        $function = trim($function);

        if ($function === '') {
            return null;
        }

        // PHP: drop the argument list; anonymous classes embed file paths and null bytes.
        $function = preg_replace('/\(.*$/s', '', $function) ?? $function;
        $function = preg_replace('/class@anonymous[^\s:>-]*/', 'class@anonymous', $function) ?? $function;

        return $function === '' ? null : $function;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private static function nodeLocation(string $location): array
    {
        if (preg_match('/^(?<file>.+?):(?<line>\d+)(?::\d+)?$/', trim($location), $m) === 1) {
            return [self::normalizeFile($m['file']), (int) $m['line']];
        }

        return [null, null];
    }

    private static function inApp(?string $normalizedFile, ?string $rawFile): bool
    {
        if ($normalizedFile === null) {
            return false;
        }

        foreach (['vendor/', 'node_modules/', 'node:', 'internal/'] as $prefix) {
            if (str_starts_with($normalizedFile, $prefix)) {
                return false;
            }
        }

        foreach (['/vendor/', '/node_modules/', '<anonymous>', '[internal'] as $marker) {
            if (str_contains($normalizedFile, $marker) || str_contains((string) $rawFile, $marker)) {
                return false;
            }
        }

        return true;
    }
}
