<?php

namespace Falak\Previews\Application;

use Closure;
use Illuminate\Support\Str;

/**
 * A preview host: the project's pattern (`pr-{number}-{service}` by default; `{project}` also works) as one DNS label
 * under the preview domain. Several projects (or organizations) share the domain: a label already taken gets a short
 * suffix derived from the project, then a counter.
 */
final class PreviewHosts
{
    public const PLACEHOLDERS = ['{number}', '{service}', '{project}'];

    /**
     * @param  Closure(string): bool  $available
     */
    public static function host(string $pattern, int $number, string $service, string $project, string $projectId, string $domain, Closure $available): string
    {
        // Room for the suffix within 63 characters.
        $label = trim(substr(self::label(str_replace(self::PLACEHOLDERS, [(string) $number, Str::slug($service), Str::slug($project)], $pattern)), 0, 52), '-');
        $suffix = substr(hash('sha256', $projectId), 0, 5);

        foreach ([$label, self::label("{$label}-{$suffix}")] as $candidate) {
            if ($available("{$candidate}.{$domain}")) {
                return "{$candidate}.{$domain}";
            }
        }

        for ($i = 2; ; $i++) {
            $candidate = self::label("{$label}-{$suffix}-{$i}");

            if ($available("{$candidate}.{$domain}")) {
                return "{$candidate}.{$domain}";
            }
        }
    }

    /** A valid DNS label: lowercase letters, digits and dashes, at most 63 characters. */
    public static function label(string $value): string
    {
        $label = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($value))), '-');

        return trim(substr($label !== '' ? $label : 'preview', 0, 63), '-');
    }

    public static function validPattern(string $pattern): bool
    {
        return str_contains($pattern, '{number}') && str_contains($pattern, '{service}')
            && preg_match('/^[a-z0-9{}-]{1,100}$/', $pattern) === 1;
    }
}
