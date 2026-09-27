<?php

namespace Kiln\Templates\Application\Compose;

use InvalidArgumentException;

/**
 * Kiln placeholders, rendered once when a site is created from a template (docs/COMPOSE_TEMPLATES.md §2):
 *
 *   ${{ kiln.url(<service>) }}     https URL of a public service
 *   ${{ kiln.domain(<service>) }}  its domain
 *   ${{ kiln.site }}               the site slug
 *
 * Other `${{ … }}` expressions (variable references like `${{ postgres.DATABASE_URL }}`) are left alone for
 * Projects to resolve at deploy time.
 */
final class KilnPlaceholders
{
    private const PATTERN = '/\$\{\{\s*kiln\.(?:(url|domain)\(\s*([A-Za-z0-9][A-Za-z0-9_.-]*)\s*\)|(site))\s*\}\}/';

    /** Kiln-provided compose variables (added by the runtime to every release). */
    public const RUNTIME_VARIABLES = ['KILN_SITE_ID', 'KILN_SERVER_ID', 'KILN_DEPLOYMENT_ID', 'KILN_RELEASE_ID'];

    /**
     * @param  array<string, string>  $domains  public service => domain
     *
     * @throws InvalidArgumentException for a url()/domain() of a service without a domain
     */
    public static function render(string $text, array $domains, string $site): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($domains, $site) {
            if (($m[3] ?? '') === 'site') {
                return $site;
            }

            $domain = $domains[$m[2]] ?? throw new InvalidArgumentException("kiln.{$m[1]}({$m[2]}): {$m[2]} is not a public service");

            return $m[1] === 'url' ? "https://{$domain}" : $domain;
        }, $text);
    }

    /**
     * Problems with a `${{ … }}` expression in a compose file (null when it is a valid Kiln placeholder).
     *
     * @param  list<string>  $publicServices
     */
    public static function problem(string $expression, array $publicServices): ?string
    {
        if (preg_match('/^kiln\.site$/', $expression) === 1) {
            return null;
        }

        if (preg_match('/^kiln\.(url|domain)\(\s*([A-Za-z0-9][A-Za-z0-9_.-]*)\s*\)$/', $expression, $m) === 1) {
            return in_array($m[2], $publicServices, true) ? null : "\${{ {$expression} }}: {$m[2]} is not a public service";
        }

        if (str_starts_with($expression, 'kiln.')) {
            return "\${{ {$expression} }}: unknown Kiln placeholder (kiln.url(service), kiln.domain(service), kiln.site)";
        }

        return "\${{ {$expression} }}: variable references belong in input defaults, not in compose.yaml";
    }

    /**
     * @return list<array{0: string, 1: ?string}> [function, service] of every Kiln placeholder in the text
     */
    public static function find(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => ($m[3] ?? '') === 'site' ? ['site', null] : [$m[1], $m[2]], $matches);
    }
}
