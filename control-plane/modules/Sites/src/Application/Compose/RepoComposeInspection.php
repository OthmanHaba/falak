<?php

namespace Kiln\Sites\Application\Compose;

use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\Data\ComposeConfig;
use Kiln\Sites\Contracts\Data\ComposeRewrites;
use Kiln\Sites\Contracts\Data\ComposeSummary;
use Kiln\Sites\Infrastructure\Compose\EloquentComposeSites;
use Kiln\Sites\Infrastructure\Compose\YamlComposeInspector;
use Kiln\SourceControl\Contracts\Exceptions\NoApi;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\SourceControlGateway;

/**
 * What Kiln sees in a repository's compose app before deploying it (docs/plans/COMPOSE_APPS.md, flow step 3–5):
 * the merged project, its services with what Kiln can do with each, the variables it needs, and the adjustments
 * Kiln will make. Files are read through the provider API (under the site's root directory, like the builder);
 * plain git servers report `no_api`.
 */
final class RepoComposeInspection
{
    /** Images Kiln can replace with a managed database (Databases engines). */
    private const DATABASE_IMAGES = [
        'postgresql' => '/(^|\/)(postgres|postgis|postgresql)(:|@|$)/',
        'mysql' => '/(^|\/)(mysql|percona)(:|@|$)/',
        'mariadb' => '/(^|\/)mariadb(:|@|$)/',
    ];

    /** Most repository paths checked for one inspection, and the time allowed for them. */
    public const MAX_LOOKUPS = 100;

    public const LOOKUP_SECONDS = 20;

    public function __construct(
        private readonly SourceControlGateway $git,
        private readonly YamlComposeInspector $inspector,
    ) {}

    /**
     * Compose files in the repository (suggestions for the path field; the user still chooses), relative to $root.
     *
     * @return list<string>
     */
    public function candidates(string $connectionId, string $repository, string $ref, ?string $root = null): array
    {
        $prefix = self::prefix($root);

        return array_values(array_map(
            fn (string $path) => substr($path, strlen($prefix)),
            array_filter(
                $this->git->tree($connectionId, $repository, $ref, '*compose*.y*ml'),
                fn (string $path) => preg_match('/\.ya?ml$/i', $path) === 1 && str_starts_with($path, $prefix),
            ),
        ));
    }

    /**
     * @param  list<string>  $files  relative to $root
     * @param  list<string>  $profiles
     * @param  list<string>  $public  public service names (healthcheck warnings)
     * @param  bool  $full  include the YAML (original/adjusted) and env-file values (people who may change the site)
     * @return array<string, mixed>
     */
    public function inspect(string $connectionId, string $repository, string $ref, array $files, array $profiles, ?ComposeConfig $config = null, array $public = [], ?ComposeRewrites $rewrites = null, ?string $root = null, bool $full = true): array
    {
        $prefix = self::prefix($root);
        $cache = [];
        $reader = function (string $path) use (&$cache, $connectionId, $repository, $ref, $prefix): ?string {
            return array_key_exists($path, $cache) ? $cache[$path] : ($cache[$path] = $this->git->file($connectionId, $repository, $ref, $prefix.$path));
        };
        $warnings = [];

        try {
            $project = ComposeProject::load($reader, $files, $profiles);
            // Paths the project mounts or reads that exist (files or folders): checked one by one, which works for
            // repositories of any size; capped in number and time.
            $present = [];
            $deadline = microtime(true) + self::LOOKUP_SECONDS;
            $references = array_values(array_diff(ComposeProject::references($project['doc']), KilnAdjustments::KILN_FILES));

            foreach ($references as $i => $path) {
                if ($i >= self::MAX_LOOKUPS || microtime(true) > $deadline) {
                    $warnings[] = 'Only the first '.$i.' of '.count($references).' repository paths the stack mounts were checked; the rest are checked at deploy time.';
                    break;
                }

                if (! KilnAdjustments::validAssetPath($path)) {
                    $warnings[] = "{$path} can’t be shipped to the servers (its name has a backslash or control character); it is treated as missing.";

                    continue;
                }

                if ($this->git->exists($connectionId, $repository, $ref, $prefix.$path)) {
                    $present[] = $path;
                }
            }
        } catch (NoApi $e) {
            return ['no_api' => true, 'message' => $e->getMessage()];
        } catch (ComposeProjectException|SourceControlException $e) {
            return ['no_api' => false, 'errors' => [$e->getMessage()], 'services' => [], 'variables' => [], 'adjustments' => [], 'warnings' => [], 'violations' => []];
        }

        $doc = $project['doc'];
        $yaml = EloquentComposeSites::dump($doc);
        $summary = $this->inspector->parse($yaml);
        $config ??= new ComposeConfig(ComposeSource::Repo, $files[0] ?? null, [], files: $files, profiles: $profiles);
        $adjusted = KilnAdjustments::apply($doc, $config, $present, $rewrites ?? new ComposeRewrites, $public);

        return [
            'no_api' => false,
            'files' => $project['files'],
            'services' => $this->services($doc, $summary, $present, $config),
            // What the stack still needs once services moved to Kiln are gone.
            'variables' => $this->variables($adjusted['doc'], $reader, $present, $full),
            'volumes' => $summary->volumes,
            'adjustments' => $adjusted['adjustments'],
            'missing' => array_values(array_filter($references, fn (string $path) => ! KilnAdjustments::inRepo($path, $present))),
            'violations' => $summary->violations,
            'errors' => [...$summary->errors, ...$adjusted['errors']],
            'warnings' => [...$summary->warnings, ...$adjusted['warnings'], ...$warnings],
            ...($full ? ['original' => $yaml, 'adjusted' => EloquentComposeSites::dump($adjusted['doc'])] : []),
        ];
    }

    /** "apps/shop" → "apps/shop/" ("" for the repository root). */
    private static function prefix(?string $root): string
    {
        $root = trim((string) $root, '/');

        return $root === '' || $root === '.' ? '' : ComposeProject::clean($root).'/';
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $present
     * @return list<array<string, mixed>>
     */
    private function services(array $doc, ComposeSummary $summary, array $present, ComposeConfig $config): array
    {
        $out = [];

        foreach ($summary->services as $service) {
            $definition = (array) ($doc['services'][$service->name] ?? []);
            $build = $definition['build'] ?? null;
            $engine = null;

            foreach (self::DATABASE_IMAGES as $candidate => $pattern) {
                if ($service->image !== null && ! $service->build && preg_match($pattern, strtolower($service->image)) === 1) {
                    $engine = $candidate;
                    break;
                }
            }

            $out[] = [
                ...$service->toArray(),
                'build_context' => is_string($build) ? $build : (is_array($build) ? ($build['context'] ?? '.') : null),
                'binds' => array_map(fn (string $source) => [
                    'source' => $source,
                    'in_repo' => str_starts_with($source, './') && (in_array(ComposeProject::clean($source), KilnAdjustments::KILN_FILES, true) || KilnAdjustments::inRepo(ComposeProject::clean($source), $present)),
                    'key' => "{$service->name}:{$source}",
                ], $service->bindMounts),
                'env_files' => array_values(array_map(function (mixed $entry) use ($present) {
                    $path = (string) (is_array($entry) ? ($entry['path'] ?? '') : $entry);
                    $clean = str_starts_with($path, './') ? ComposeProject::clean($path) : $path;

                    return ['path' => $clean, 'in_repo' => str_starts_with($path, './') && ($clean === KilnAdjustments::KILN_ENV || KilnAdjustments::inRepo($clean, $present))];
                }, self::envFileEntries($definition))),
                'variables' => array_keys(self::interpolations(self::text($definition))),
                'database_engine' => $engine,
                'mode' => $config->mode($service->name),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<mixed>
     */
    private static function envFileEntries(array $definition): array
    {
        return is_array($definition['env_file'] ?? null) && array_is_list($definition['env_file']) ? $definition['env_file'] : (isset($definition['env_file']) ? [$definition['env_file']] : []);
    }

    /**
     * Variables the stack reads: `${VAR}` interpolations (required only for `${VAR:?…}` / `${VAR?…}`; a plain
     * `${VAR}` without a value becomes empty) and the keys of its repository env files (their values as defaults,
     * shown only with $full). Kiln's own KILN_* variables are left out.
     *
     * @param  array<string, mixed>  $doc  the adjusted project (services moved to Kiln are gone)
     * @param  callable(string): ?string  $reader
     * @param  list<string>  $present
     * @return list<array{name: string, default: ?string, required: bool, services: list<string>, source: string}>
     */
    private function variables(array $doc, callable $reader, array $present, bool $full): array
    {
        $variables = [];

        foreach ((array) ($doc['services'] ?? []) as $name => $definition) {
            foreach (self::interpolations(self::text($definition)) as $variable => [$default, $required]) {
                $entry = $variables[$variable] ?? ['name' => $variable, 'default' => null, 'required' => false, 'services' => [], 'source' => 'compose'];
                $entry['default'] ??= $default;
                $entry['required'] = $entry['required'] || $required;
                $entry['services'][] = (string) $name;
                $variables[$variable] = $entry;
            }

            foreach (self::envFileEntries((array) $definition) as $env) {
                $path = (string) (is_array($env) ? ($env['path'] ?? '') : $env);
                // The adjusted project points shipped env files at ./repo/<path>.
                $clean = str_starts_with($path, './'.KilnAdjustments::REPO_DIR.'/') ? substr($path, strlen('./'.KilnAdjustments::REPO_DIR.'/')) : null;

                if ($clean === null || ! KilnAdjustments::inRepo($clean, $present)) {
                    continue;
                }

                try {
                    $content = $reader($clean);
                } catch (SourceControlException) {
                    $content = null;
                }

                foreach (self::envFile((string) $content) as $key => $value) {
                    $entry = $variables[$key] ?? ['name' => $key, 'default' => $full ? $value : null, 'required' => false, 'services' => [], 'source' => "env_file {$clean}"];
                    $entry['services'][] = (string) $name;
                    $variables[$key] = $entry;
                }
            }
        }

        $variables = array_filter($variables, fn (array $v) => ! str_starts_with($v['name'], 'KILN_'));

        foreach ($variables as &$variable) {
            $variable['services'] = array_values(array_unique($variable['services']));
        }

        ksort($variables);

        return array_values($variables);
    }

    /**
     * Compose interpolations in a text: name => [default, required]. Only `${VAR:?err}` / `${VAR?err}` are required;
     * `$$` escapes are skipped.
     *
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function interpolations(string $text): array
    {
        $text = str_replace('$$', '', $text);
        preg_match_all('/\$(?:\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?+])((?:[^}\\\\]|\\\\.)*))?\}|([A-Za-z_][A-Za-z0-9_]*))/', $text, $matches, PREG_SET_ORDER);
        $out = [];

        foreach ($matches as $match) {
            $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[4] ?? '');
            $operator = $match[2] ?? '';
            $default = in_array($operator, ['-', ':-'], true) ? stripslashes($match[3] ?? '') : null;
            $current = $out[$name] ?? [null, false];
            $out[$name] = [$current[0] ?? $default, $current[1] || str_contains($operator, '?')];
        }

        return $out;
    }

    /** Every string (keys and values) of a compose fragment, one per line. */
    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $out = '';

            foreach ($value as $key => $item) {
                $out .= (is_string($key) ? $key."\n" : '').self::text($item);
            }

            return $out;
        }

        return is_scalar($value) ? $value."\n" : '';
    }

    /**
     * KEY=VALUE lines of an env file (comments, blank lines and `export` prefixes skipped; quotes removed).
     *
     * @return array<string, string>
     */
    public static function envFile(string $content): array
    {
        $out = [];

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = (string) preg_replace('/^export\s+/', '', $line);

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $value = $m[2];

            if (preg_match('/^([\'"])(.*)\1$/', $value, $q) === 1) {
                $value = $q[2];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }

            $out[$m[1]] = $value;
        }

        return $out;
    }
}
