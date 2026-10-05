<?php

namespace Kiln\Sites\Application\Compose;

use Kiln\Sites\Contracts\Data\ComposeConfig;
use Kiln\Sites\Contracts\Data\ComposeRewrites;

/**
 * What Kiln changes in a compose project before it runs (docs/plans/COMPOSE_APPS.md "Compatibility layer"). The
 * repository's file is never edited: this runs at render time, and the preview shows the same list.
 *
 * For repository projects ($repoFiles given) paths are root-relative (ComposeProject) and the files the project
 * mounts are shipped under <release>/repo/: bind sources, env_file entries and configs/secrets files found in the
 * repository point there; bind sources the repository lacks (data folders) become named volumes unless the user
 * keeps them; env files it lacks are replaced by Kiln's variables. Inline projects keep their paths.
 */
final class KilnAdjustments
{
    /** Rewritten keys added to a service that didn't set them (ServiceReferences, cache mode: next to a REDIS_HOST). */
    private const ADDED_KEYS = ['REDIS_PORT' => true, 'REDIS_PASSWORD' => true];

    /** The release directory holding shipped repository files (agent docker.AssetsDir). */
    public const REPO_DIR = 'repo';

    /** Kiln's project env file: every site variable (the agent writes it next to compose.yaml). */
    public const KILN_ENV = '.env';

    /** Files Kiln writes into every release: bind mounts and env files naming them stay on Kiln's copies. */
    public const KILN_FILES = ['.env', 'compose.yaml'];

    /**
     * A relative repository path the servers can write (no ".", ".." or empty segments, backslashes or control
     * characters). Same rule as kiln-builder, the agent and Builds.
     */
    public static function validAssetPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  ?list<string>  $repoFiles  files of the repository the release ships (null: not a repository project)
     * @param  ComposeRewrites  $rewrites  per service, the variables that pointed at extracted services
     * @param  list<string>  $publicServices
     * @return array{doc: array<string, mixed>, adjustments: list<array{kind: string, service: ?string, detail: string, key?: string}>, warnings: list<string>, errors: list<string>}
     */
    public static function apply(array $doc, ComposeConfig $config, ?array $repoFiles, ComposeRewrites $rewrites, array $publicServices = []): array
    {
        $adjustments = [];
        $warnings = [];
        $errors = [];
        $note = function (string $kind, ?string $service, string $detail, ?string $key = null) use (&$adjustments): void {
            $adjustments[] = array_filter(['kind' => $kind, 'service' => $service, 'detail' => $detail, 'key' => $key], fn ($v) => $v !== null) + ['service' => $service];
        };
        $services = is_array($doc['services'] ?? null) ? $doc['services'] : [];
        $extracted = $config->extracted();
        $references = $repoFiles !== null ? array_values(array_diff(ComposeProject::references($doc), self::KILN_FILES)) : [];

        foreach ($extracted as $name) {
            if (array_key_exists($name, $services)) {
                unset($services[$name]);
                $mode = $config->mode($name) === ComposeConfig::MODE_DATABASE ? 'a Kiln database' : 'its own Kiln service';
                $note('removed', $name, "Runs as {$mode} instead of in the stack.");
            }
        }

        $keepBinds = array_map('strval', (array) ($config->adjustments['keep_binds'] ?? []));
        $restartDefaults = [];
        $repo = $repoFiles !== null;

        foreach ($services as $name => $service) {
            $name = (string) $name;
            $service = is_array($service) ? $service : [];

            if (array_key_exists('depends_on', $service) && $extracted !== []) {
                $service['depends_on'] = self::withoutDependencies($service['depends_on'], $extracted);

                if ($service['depends_on'] === []) {
                    unset($service['depends_on']);
                }
            }

            if (($own = $rewrites->forService((string) $name)) !== [] && is_array($service['environment'] ?? null)) {
                $service['environment'] = self::rewriteEnvironment($service['environment'], $own, (string) $name, $note);
            }

            if (! $repo) {
                $services[$name] = $service;

                continue;
            }

            if (isset($service['container_name'])) {
                $note('container_name', $name, 'container_name removed: Kiln names containers per environment and release.');
                unset($service['container_name']);
            }

            if (! isset($service['restart']) && ! isset($service['deploy']['restart_policy'])) {
                $service['restart'] = 'unless-stopped';
                $restartDefaults[] = $name;
            }

            if (is_array($service['volumes'] ?? null)) {
                foreach ($service['volumes'] as $i => $volume) {
                    $source = is_string($volume) ? explode(':', $volume, 2)[0] : (is_array($volume) && ($volume['type'] ?? null) === 'bind' ? (string) ($volume['source'] ?? '') : null);

                    if ($source === null || ! ($source === '.' || str_starts_with($source, './'))) {
                        continue;
                    }

                    $path = ComposeProject::clean($source);
                    $key = "{$name}:{$source}";

                    if (in_array($path, self::KILN_FILES, true)) {
                        // Kiln writes these into the release itself (stacks bind them, e.g. ./.env:/app/.env).
                        continue;
                    }

                    if ($path !== '.' && self::inRepo($path, $repoFiles)) {
                        $target = './'.self::REPO_DIR.'/'.$path;
                    } elseif (in_array($key, $keepBinds, true)) {
                        $target = './'.self::REPO_DIR.'/'.$path;
                        $note('bind_kept', $name, "{$source} is not in the repository: kept as an empty folder of each release.", $key);
                    } else {
                        $volumeName = self::volumeName($name, $path);
                        $doc['volumes'][$volumeName] ??= null;
                        $note('bind_to_volume', $name, "{$source} is not in the repository: mounted as the named volume {$volumeName} (kept across deploys).", $key);

                        if (is_string($volume)) {
                            $service['volumes'][$i] = $volumeName.':'.explode(':', $volume, 2)[1];
                        } else {
                            unset($volume['bind']);
                            $service['volumes'][$i] = ['type' => 'volume', 'source' => $volumeName] + $volume;
                        }

                        continue;
                    }

                    if (is_string($volume)) {
                        $service['volumes'][$i] = $target.':'.explode(':', $volume, 2)[1];
                    } else {
                        $service['volumes'][$i]['source'] = $target;
                    }
                }
            }

            if (array_key_exists('env_file', $service)) {
                [$service['env_file'], $missing] = self::envFiles($service['env_file'], $repoFiles);

                // Only a service whose env file is missing gets every site variable instead (others read theirs
                // through ${VAR} interpolation, so third-party images don't receive unrelated secrets).
                foreach ($missing as $path) {
                    $note('env_file_missing', $name, "env_file {$path} is not in the repository: the service gets the site's variables instead.");
                }

                if ($service['env_file'] === []) {
                    unset($service['env_file']);
                }
            }

            $services[$name] = $service;
        }

        if ($restartDefaults !== []) {
            $note('restart', null, 'restart: unless-stopped for '.implode(', ', $restartDefaults).' (no restart policy set).');
        }

        foreach (['configs', 'secrets'] as $kind) {
            foreach ((array) ($doc[$kind] ?? []) as $item => $definition) {
                $file = is_array($definition) && is_string($definition['file'] ?? null) ? $definition['file'] : null;

                if (! $repo || $file === null || ! str_starts_with($file, './')) {
                    continue;
                }

                $path = ComposeProject::clean($file);

                if (self::inRepo($path, $repoFiles)) {
                    $doc[$kind][$item]['file'] = './'.self::REPO_DIR.'/'.$path;
                } else {
                    $errors[] = "{$kind}.{$item}: {$path} is not in the repository.";
                }
            }
        }

        foreach ($publicServices as $public) {
            if (isset($services[$public]) && ! is_array($services[$public]['healthcheck'] ?? null)) {
                $warnings[] = "Public service {$public} has no healthcheck: Kiln can only check it through its domain.";
            }
        }

        if ($repo && ($shipped = count(array_filter($references, fn (string $path) => self::inRepo($path, $repoFiles)))) > 0) {
            $note('repo_files', null, "{$shipped} repository path(s) the stack mounts or reads are shipped with each release.");
        }

        $doc['services'] = $services;

        if (array_key_exists('volumes', $doc) && $doc['volumes'] === []) {
            unset($doc['volumes']);
        }

        return ['doc' => $doc, 'adjustments' => $adjustments, 'warnings' => $warnings, 'errors' => $errors];
    }

    /** A repository path (file or folder) the release ships. */
    public static function inRepo(string $path, ?array $repoFiles): bool
    {
        foreach ((array) $repoFiles as $file) {
            if ($file === $path || str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /** `app` + `data/uploads` → `app-data-uploads` (a valid volume name). */
    public static function volumeName(string $service, string $path): string
    {
        return trim((string) preg_replace('/[^a-z0-9_.-]+/', '-', strtolower("{$service}-{$path}")), '-.') ?: 'data';
    }

    /**
     * @param  list<string>  $extracted
     * @return array<int|string, mixed>
     */
    private static function withoutDependencies(mixed $dependsOn, array $extracted): array
    {
        if (! is_array($dependsOn)) {
            return [];
        }

        if (array_is_list($dependsOn)) {
            return array_values(array_filter($dependsOn, fn ($d) => ! in_array((string) $d, $extracted, true)));
        }

        return array_diff_key($dependsOn, array_flip($extracted));
    }

    /**
     * @param  array<int|string, mixed>  $environment
     *                                                 The service's own rewritten keys read their own project variable (ComposeRewrites::variable()), so the same key
     *                                                 in two services can point at different databases.
     * @param  array<string, string>  $rewrites  this service's variable → replacement
     * @return array<int|string, mixed>
     */
    private static function rewriteEnvironment(array $environment, array $rewrites, string $service, callable $note): array
    {
        if (array_is_list($environment)) {
            foreach ($environment as $i => $entry) {
                $key = explode('=', (string) $entry, 2)[0];

                if (isset($rewrites[$key])) {
                    $environment[$i] = $key.'=${'.ComposeRewrites::variable($service, $key).'}';
                    $note('variable', $service, "{$key} points at {$rewrites[$key]}.");
                    unset($rewrites[$key]);
                }
            }

            // A Kiln Redis' REDIS_PORT / REDIS_PASSWORD next to a REDIS_HOST the service set without them are added (the
            // instance listens on 6380+ and always has a password). Other keys the service no longer sets stay out.
            foreach (array_intersect_key($rewrites, self::ADDED_KEYS) as $key => $replacement) {
                $environment[] = $key.'=${'.ComposeRewrites::variable($service, $key).'}';
                $note('variable', $service, "{$key} added: {$replacement}.");
            }

            return $environment;
        }

        foreach ($environment as $key => $value) {
            if (isset($rewrites[(string) $key])) {
                $environment[$key] = '${'.ComposeRewrites::variable($service, (string) $key).'}';
                $note('variable', $service, "{$key} points at {$rewrites[(string) $key]}.");
                unset($rewrites[(string) $key]);
            }
        }

        foreach (array_intersect_key($rewrites, self::ADDED_KEYS) as $key => $replacement) {
            $environment[$key] = '${'.ComposeRewrites::variable($service, (string) $key).'}';
            $note('variable', $service, "{$key} added: {$replacement}.");
        }

        return $environment;
    }

    /**
     * Env files in the repository point at the shipped copies; missing ones are dropped. Kiln's .env comes last.
     *
     * @param  ?list<string>  $repoFiles
     * @return array{0: list<mixed>, 1: list<string>} env_file entries, missing paths
     */
    private static function envFiles(mixed $value, ?array $repoFiles): array
    {
        $entries = is_array($value) && array_is_list($value) ? $value : [$value];
        $out = [];
        $missing = [];

        foreach ($entries as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            $required = ! is_array($entry) || ($entry['required'] ?? true) !== false;

            if (! is_string($path) || ! str_starts_with($path, './')) {
                $out[] = $entry;

                continue;
            }

            $clean = ComposeProject::clean($path);

            if ($clean === self::KILN_ENV) {
                $out[] = $entry; // Kiln's own .env in the release
            } elseif (self::inRepo($clean, $repoFiles)) {
                $shipped = './'.self::REPO_DIR.'/'.$clean;
                $out[] = is_array($entry) ? ['path' => $shipped] + $entry : $shipped;
            } elseif ($required) {
                $missing[] = $clean;
            }
        }

        if ($missing !== [] && ! in_array(self::KILN_ENV, array_map(fn ($e) => is_array($e) ? ($e['path'] ?? null) : $e, $out), true)) {
            $out[] = self::KILN_ENV;
        }

        return [$out, $missing];
    }
}
