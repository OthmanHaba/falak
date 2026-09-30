<?php

namespace Kiln\Functions\Application;

use Illuminate\Validation\ValidationException;

/**
 * A function's files: validated, normalized (sorted by path) and hashed. The hash covers the entrypoint and every
 * file, so equal code always has the same hash (it is the deployment's commit).
 */
final class Code
{
    public const PATH_PATTERN = '/^(?!.*(?:^|\/)\.\.?(?:\/|$))[A-Za-z0-9_][A-Za-z0-9_.\-]*(?:\/[A-Za-z0-9_][A-Za-z0-9_.\-]*)*$/';

    /**
     * @param  mixed  $input  {path: content} or [{path, content}]
     * @return array<string, string> path => content, sorted by path
     *
     * @throws ValidationException
     */
    public static function files(mixed $input, string $entrypoint, string $field = 'files'): array
    {
        $files = [];

        foreach (is_array($input) ? $input : [] as $key => $value) {
            [$path, $content] = is_array($value) ? [(string) ($value['path'] ?? ''), $value['content'] ?? null] : [(string) $key, $value];

            if (preg_match(self::PATH_PATTERN, $path) !== 1 || strlen($path) > 200) {
                throw ValidationException::withMessages([$field => "“{$path}” is not a valid file path (relative, letters, digits, . _ - and /)."]);
            }

            if (! is_string($content)) {
                throw ValidationException::withMessages([$field => "{$path} has no content."]);
            }

            $files[$path] = $content;
        }

        if ($files === []) {
            throw ValidationException::withMessages([$field => 'The function has no code.']);
        }

        if (count($files) > (int) config('functions.max_files', 50)) {
            throw ValidationException::withMessages([$field => 'A function can have at most '.config('functions.max_files', 50).' files.']);
        }

        if (! array_key_exists($entrypoint, $files)) {
            throw ValidationException::withMessages([$field => "The entrypoint {$entrypoint} is missing."]);
        }

        if (self::size($files) > (int) config('functions.max_bytes', 1048576)) {
            throw ValidationException::withMessages([$field => 'The code is larger than '.round(config('functions.max_bytes', 1048576) / 1024).' KB.']);
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function size(array $files): int
    {
        return array_sum(array_map(fn (string $content, string $path) => strlen($path) + strlen($content), $files, array_keys($files)));
    }

    /**
     * @param  array<string, string>  $files  sorted by path
     */
    public static function hash(array $files, string $entrypoint): string
    {
        return hash('sha256', (string) json_encode(['entrypoint' => $entrypoint, 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
