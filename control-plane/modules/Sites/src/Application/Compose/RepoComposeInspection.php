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
 * Kiln will make. Files are read through the provider API; plain git servers report `no_api`.
 */
final class RepoComposeInspection
{
    /** Images Kiln can replace with a managed database (Databases engines). */
    private const DATABASE_IMAGES = [
        'postgresql' => '/(^|\/)(postgres|postgis|postgresql)(:|@|$)/',
        'mysql' => '/(^|\/)(mysql|percona)(:|@|$)/',
        'mariadb' => '/(^|\/)mariadb(:|@|$)/',
    ];

    public function __construct(
        private readonly SourceControlGateway $git,
        private readonly YamlComposeInspector $inspector,
    ) {}

    /**
     * Compose files in the repository (suggestions for the path field; the user still chooses).
     *
     * @return list<string>
     */
    public function candidates(string $connectionId, string $repository, string $ref): array
    {
        return array_values(array_filter(
            $this->git->tree($connectionId, $repository, $ref, '*compose*.y*ml'),
            fn (string $path) => preg_match('/\.ya?ml$/i', $path) === 1,
        ));
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $profiles
     * @param  list<string>  $public  public service names (healthcheck warnings)
     * @return array<string, mixed>
     */
    public function inspect(string $connectionId, string $repository, string $ref, array $files, array $profiles, ?ComposeConfig $config = null, array $public = [], ?ComposeRewrites $rewrites = null): array
    {
        $cache = [];
        $reader = function (string $path) use (&$cache, $connectionId, $repository, $ref): ?string {
            return array_key_exists($path, $cache) ? $cache[$path] : ($cache[$path] = $this->git->file($connectionId, $repository, $ref, $path));
        };

        try {
            $project = ComposeProject::load($reader, $files, $profiles);
            $tree = $this->git->tree($connectionId, $repository, $ref, '**');

            // Large repositories list only part of their tree: look up referenced files that weren't listed.
            foreach (ComposeProject::references($project['doc']) as $path) {
                if (! KilnAdjustments::inRepo($path, $tree) && $reader($path) !== null) {
                    $tree[] = $path;
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
        $adjusted = KilnAdjustments::apply($doc, $config, $tree, $rewrites ?? new ComposeRewrites, $public);
        $references = ComposeProject::references($doc);

        return [
            'no_api' => false,
            'files' => $project['files'],
            'services' => $this->services($doc, $summary, $tree, $config),
            'variables' => $this->variables($doc, $reader, $tree),
            'volumes' => $summary->volumes,
            'adjustments' => $adjusted['adjustments'],
            'missing' => array_values(array_filter($references, fn (string $path) => ! KilnAdjustments::inRepo($path, $tree))),
            'violations' => $summary->violations,
            'errors' => [...$summary->errors, ...$adjusted['errors']],
            'warnings' => [...$summary->warnings, ...$adjusted['warnings']],
            'original' => $yaml,
            'adjusted' => EloquentComposeSites::dump($adjusted['doc']),
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $tree
     * @return list<array<string, mixed>>
     */
    private function services(array $doc, ComposeSummary $summary, array $tree, ComposeConfig $config): array
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
                    'in_repo' => str_starts_with($source, './') && KilnAdjustments::inRepo(ComposeProject::clean($source), $tree),
                    'key' => "{$service->name}:{$source}",
                ], $service->bindMounts),
                'env_files' => array_values(array_map(function (mixed $entry) use ($tree) {
                    $path = (string) (is_array($entry) ? ($entry['path'] ?? '') : $entry);

                    return ['path' => str_starts_with($path, './') ? ComposeProject::clean($path) : $path, 'in_repo' => str_starts_with($path, './') && KilnAdjustments::inRepo(ComposeProject::clean($path), $tree)];
                }, is_array($definition['env_file'] ?? null) && array_is_list($definition['env_file']) ? $definition['env_file'] : (isset($definition['env_file']) ? [$definition['env_file']] : []))),
                'variables' => array_keys(self::interpolations(self::text($definition))),
                'database_engine' => $engine,
                'mode' => $config->mode($service->name),
            ];
        }

        return $out;
    }

    /**
     * Variables the stack needs: `${VAR}` interpolations (required unless they have a default) and the keys of its
     * env files (their values as defaults). Kiln's own KILN_* variables are left out.
     *
     * @param  array<string, mixed>  $doc
     * @param  callable(string): ?string  $reader
     * @param  list<string>  $tree
     * @return list<array{name: string, default: ?string, required: bool, services: list<string>, source: string}>
     */
    private function variables(array $doc, callable $reader, array $tree): array
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

            foreach (is_array($definition['env_file'] ?? null) && array_is_list($definition['env_file']) ? $definition['env_file'] : (isset($definition['env_file']) ? [$definition['env_file']] : []) as $env) {
                $path = (string) (is_array($env) ? ($env['path'] ?? '') : $env);

                if (! str_starts_with($path, './') || ! KilnAdjustments::inRepo($clean = ComposeProject::clean($path), $tree)) {
                    continue;
                }

                try {
                    $content = $reader($clean);
                } catch (SourceControlException) {
                    $content = null;
                }

                foreach (self::envFile((string) $content) as $key => $value) {
                    $entry = $variables[$key] ?? ['name' => $key, 'default' => $value, 'required' => false, 'services' => [], 'source' => "env_file {$clean}"];
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
     * Compose interpolations in a text: name => [default, required]. `$$` escapes are skipped.
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
            $required = $operator === '' || str_contains($operator, '?');
            $current = $out[$name] ?? [null, false];
            $out[$name] = [$current[0] ?? $default, $current[1] || ($required && $default === null && ! in_array($operator, ['+', ':+'], true))];
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
