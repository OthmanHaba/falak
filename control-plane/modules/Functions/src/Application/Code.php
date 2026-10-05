<?php

namespace Falak\Functions\Application;

use Illuminate\Validation\ValidationException;

/**
 * A function's files: validated, normalized (sorted by path) and hashed. The hash covers the entrypoint and every
 * file, so equal code always has the same hash (it is the deployment's commit).
 */
final class Code
{
    public const MAX_DEPTH = 8;

    /** Folders the runtimes' installers create in the release (dependencies, bytecode caches). */
    public const RESERVED = ['node_modules', '__pycache__'];

    public const PATH_PATTERN = '/^(?!.*(?:^|\/)\.\.?(?:\/|$))[A-Za-z0-9_][A-Za-z0-9_.\-]*(?:\/[A-Za-z0-9_][A-Za-z0-9_.\-]*)*$/';

    /**
     * @param  mixed  $input  {path: content} or [{path, content}]
     * @return array<string, string> path => content, sorted by path
     *
     * @throws ValidationException
     */
    public static function files(mixed $input, string $entrypoint, string $field = 'files'): array
    {
        $maxFiles = (int) config('functions.max_files', 50);
        $maxBytes = (int) config('functions.max_bytes', 1048576);
        $input = is_array($input) ? $input : [];

        // Count and size first: everything after is per path, and a huge payload must fail before that.
        if (count($input) > $maxFiles) {
            throw ValidationException::withMessages([$field => "A function can have at most {$maxFiles} files."]);
        }

        $files = [];
        $size = 0;

        foreach ($input as $key => $value) {
            [$path, $content] = is_array($value) ? [(string) ($value['path'] ?? ''), $value['content'] ?? null] : [(string) $key, $value];

            if (preg_match(self::PATH_PATTERN, $path) !== 1 || strlen($path) > 200) {
                throw ValidationException::withMessages([$field => "“{$path}” is not a valid file path (relative, letters, digits, . _ - and /)."]);
            }

            if (! is_string($content)) {
                throw ValidationException::withMessages([$field => "{$path} has no content."]);
            }

            $size += strlen($path) + strlen($content);

            if ($size > $maxBytes) {
                throw ValidationException::withMessages([$field => 'The code is larger than '.round($maxBytes / 1024).' KB.']);
            }

            $files[$path] = $content;
        }

        if ($files === []) {
            throw ValidationException::withMessages([$field => 'The function has no code.']);
        }

        // PHP turns all-digit keys ("1") into ints: paths are strings from here on.
        $paths = array_map('strval', array_keys($files));
        self::tree($paths, $field);

        if (! in_array($entrypoint, $paths, true)) {
            throw ValidationException::withMessages([$field => "The entrypoint {$entrypoint} is missing."]);
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Paths the server can write as a tree: not too deep, no folder the installer owns, no file that is also a
     * folder ("lib" and "lib/db.ts"), and no two paths that differ only in case (they collide on macOS checkouts).
     *
     * @param  list<string>  $paths
     *
     * @throws ValidationException
     */
    private static function tree(array $paths, string $field): void
    {
        $isFile = array_fill_keys($paths, true);
        $folded = [];

        foreach ($paths as $path) {
            $segments = explode('/', $path);

            if (count($segments) > self::MAX_DEPTH) {
                throw ValidationException::withMessages([$field => "{$path} is nested too deep (at most ".self::MAX_DEPTH.' levels).']);
            }

            if (array_intersect($segments, self::RESERVED) !== []) {
                throw ValidationException::withMessages([$field => "{$path}: ".implode(', ', self::RESERVED).' are created on the server when the dependencies are installed.']);
            }

            $folder = '';

            foreach (array_slice($segments, 0, -1) as $segment) {
                $folder = $folder === '' ? $segment : "{$folder}/{$segment}";

                if (isset($isFile[$folder])) {
                    throw ValidationException::withMessages([$field => "{$folder} is both a file and a folder."]);
                }
            }

            $key = mb_strtolower($path);

            if (isset($folded[$key])) {
                throw ValidationException::withMessages([$field => "{$folded[$key]} and {$path} differ only in case."]);
            }

            $folded[$key] = $path;
        }
    }

    /**
     * Per-file changes from $from to $to (sorted by path).
     *
     * @param  array<string, string>  $from
     * @param  array<string, string>  $to
     * @return list<array{path: string, status: 'added'|'removed'|'modified'}>
     */
    public static function changes(array $from, array $to): array
    {
        $out = [];
        $paths = array_unique([...array_keys($from), ...array_keys($to)]);
        sort($paths, SORT_STRING);

        foreach ($paths as $path) {
            $status = match (true) {
                ! array_key_exists($path, $from) => 'added',
                ! array_key_exists($path, $to) => 'removed',
                $from[$path] !== $to[$path] => 'modified',
                default => null,
            };

            if ($status !== null) {
                $out[] = ['path' => (string) $path, 'status' => $status];
            }
        }

        return $out;
    }

    /**
     * The largest request body that can carry a valid version (JSON escaping can double the code's size), checked
     * before the body is decoded and validated.
     */
    public static function maxRequestBytes(): int
    {
        return 2 * (int) config('functions.max_bytes', 1048576) + 65536;
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function size(array $files): int
    {
        return array_sum(array_map(fn (string $content, int|string $path) => strlen((string) $path) + strlen($content), $files, array_keys($files)));
    }

    /**
     * @param  array<string, string>  $files  sorted by path
     */
    public static function hash(array $files, string $entrypoint): string
    {
        return hash('sha256', (string) json_encode(['entrypoint' => $entrypoint, 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
