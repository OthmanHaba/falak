<?php

namespace Falak\Sites\Application\Compose;

use Falak\Sites\Infrastructure\Compose\YamlComposeInspector;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * A compose project as `docker compose -f a.yml -f b.yml --profile p config` sees it: include and extends
 * resolved, files merged in order, services of inactive profiles dropped, relative paths rebased to the repository
 * root ("./docker/nginx.conf"). falak-builder applies the same rules at deploy time
 * (agent/internal/builder/composeproject.go); both are tested against contracts/compose/merge-cases.json.
 */
final class ComposeProject
{
    public const DEFAULT_FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'];

    private const MAX_DEPTH = 8;

    /** Sequences that are appended across files (duplicates dropped); other sequences are replaced. */
    private const APPEND_KEYS = ['ports', 'expose', 'dns', 'dns_search', 'dns_opt', 'tmpfs', 'cap_add', 'cap_drop', 'external_links', 'security_opt', 'env_file', 'devices', 'group_add', 'links', 'volumes_from', 'secrets', 'configs'];

    /** Keys whose list form ("K=V", or names) is a mapping. */
    private const MAPPING_KEYS = ['environment', 'labels', 'annotations', 'sysctls', 'extra_hosts', 'depends_on', 'networks'];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /** @var list<string> */
    private array $read = [];

    /**
     * @param  callable(string): ?string  $reader  root-relative path → content (null: missing)
     */
    private function __construct(private $reader) {}

    /**
     * @param  callable(string): ?string  $reader
     * @param  list<string>  $files  repository-relative, -f order (empty: the default file names)
     * @param  list<string>  $profiles
     * @return array{doc: array<string, mixed>, files: list<string>}
     *
     * @throws ComposeProjectException
     */
    public static function load(callable $reader, array $files, array $profiles): array
    {
        $loader = new self($reader);

        if ($files === []) {
            foreach (self::DEFAULT_FILES as $candidate) {
                if ($reader($candidate) !== null) {
                    $files = [$candidate];
                    break;
                }
            }

            if ($files === []) {
                throw new ComposeProjectException('No compose file found (tried '.implode(', ', self::DEFAULT_FILES).').');
            }
        }

        // Relative paths of every -f file resolve against the first file's directory (the project directory).
        $projectDir = self::dir(self::clean($files[0]));
        $merged = [];

        foreach ($files as $file) {
            $merged = self::mergeDocs($merged, $loader->file(self::repoPath('.', $file, "Compose file {$file}"), $projectDir, 0));
        }

        self::applyProfiles($merged, $profiles);

        return ['doc' => $merged, 'files' => $loader->read];
    }

    /**
     * Repository paths the project reads at runtime: bind sources inside the repository, env_file entries and
     * configs/secrets `file:` (root-relative, without "./", sorted).
     *
     * @param  array<string, mixed>  $doc
     * @return list<string>
     */
    public static function references(array $doc): array
    {
        $set = [];
        $add = function (mixed $p) use (&$set): void {
            if (! is_string($p) || ! ($p === '.' || str_starts_with($p, './'))) {
                return;
            }

            $clean = self::clean($p);

            if ($clean !== '.' && $clean !== '..' && ! str_starts_with($clean, '../')) {
                $set[$clean] = true;
            }
        };

        foreach ((array) ($doc['services'] ?? []) as $service) {
            foreach (self::asList($service['volumes'] ?? null) as $volume) {
                if (is_string($volume) && str_contains($volume, ':')) {
                    $add(explode(':', $volume, 2)[0]);
                } elseif (is_array($volume) && ($volume['type'] ?? null) === 'bind') {
                    $add($volume['source'] ?? null);
                }
            }

            foreach (self::asList($service['env_file'] ?? null) as $env) {
                $add(is_array($env) ? ($env['path'] ?? null) : $env);
            }
        }

        foreach (['configs', 'secrets'] as $kind) {
            foreach ((array) ($doc[$kind] ?? []) as $item) {
                $add(is_array($item) ? ($item['file'] ?? null) : null);
            }
        }

        $out = array_keys($set);
        sort($out);

        return array_map('strval', $out);
    }

    // ---- loading ------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function raw(string $rel): array
    {
        if (isset($this->cache[$rel])) {
            return $this->cache[$rel];
        }

        $content = ($this->reader)($rel);

        if ($content === null) {
            throw new ComposeProjectException("Compose file {$rel} not found in the repository.");
        }

        try {
            $doc = YamlComposeInspector::load($content);
        } catch (ParseException $e) {
            throw new ComposeProjectException("Compose file {$rel}: ".$e->getMessage());
        }

        $doc = is_array($doc) ? $doc : [];
        $this->read[] = $rel;

        return $this->cache[$rel] = $doc;
    }

    /**
     * One compose file with its includes and extends resolved; its relative paths resolve against $base.
     *
     * @return array<string, mixed>
     */
    private function file(string $rel, string $base, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ComposeProjectException("Compose file {$rel}: include/extends nested too deeply.");
        }

        $doc = $this->raw($rel);
        $out = [];

        if (array_key_exists('include', $doc)) {
            $entries = $doc['include'];
            unset($doc['include']);

            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new ComposeProjectException("Compose file {$rel}: include must be a list.");
            }

            foreach ($entries as $entry) {
                $paths = [];
                $projectDir = '';

                if (is_string($entry)) {
                    $paths = [$entry];
                } elseif (is_array($entry)) {
                    $paths = is_string($entry['path'] ?? null) ? [$entry['path']] : array_values(array_filter((array) ($entry['path'] ?? []), 'is_string'));
                    $projectDir = is_string($entry['project_directory'] ?? null) ? $entry['project_directory'] : '';
                }

                if ($paths === []) {
                    throw new ComposeProjectException("Compose file {$rel}: include entry without a path.");
                }

                // Several paths act like `-f a -f b`: they share the first one's directory, unless project_directory says.
                $incBase = $projectDir !== ''
                    ? self::repoPath(self::dir($rel), $projectDir, "Compose file {$rel}: include")
                    : self::dir(self::repoPath(self::dir($rel), $paths[0], "Compose file {$rel}: include {$paths[0]}"));

                foreach ($paths as $path) {
                    $out = self::mergeDocs($out, $this->file(self::repoPath(self::dir($rel), $path, "Compose file {$rel}: include {$path}"), $incBase, $depth + 1));
                }
            }
        }

        self::rebaseDoc($doc, $base);

        foreach (array_keys((array) ($doc['services'] ?? [])) as $name) {
            $doc['services'][$name] = $this->extend($rel, $doc, (string) $name, $depth, []);
        }

        return self::mergeDocs($out, $doc);
    }

    /**
     * Resolve `extends` of one (already rebased) service of $doc, read from $rel.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>
     */
    private function extend(string $rel, array $doc, string $name, int $depth, array $seen): array
    {
        $service = is_array($doc['services'][$name] ?? null) ? $doc['services'][$name] : [];

        if (! array_key_exists('extends', $service)) {
            return $service;
        }

        $key = "{$rel}#{$name}";

        if (isset($seen[$key])) {
            throw new ComposeProjectException("Compose service {$name}: extends loops.");
        }

        $seen[$key] = true;
        $extends = $service['extends'];
        unset($service['extends']);
        $target = is_string($extends) ? $extends : (string) ($extends['service'] ?? '');
        $file = is_array($extends) && is_string($extends['file'] ?? null) ? $extends['file'] : '';

        if ($target === '') {
            throw new ComposeProjectException("Compose service {$name}: extends needs a service.");
        }

        if ($file === '') {
            if (! array_key_exists($target, (array) ($doc['services'] ?? []))) {
                throw new ComposeProjectException("Compose service {$name}: extends unknown service {$target}.");
            }

            $parent = $this->extend($rel, $doc, $target, $depth, $seen);
        } else {
            $extRel = self::repoPath(self::dir($rel), $file, "Compose service {$name}: extends file {$file}");

            if ($depth + 1 > self::MAX_DEPTH) {
                throw new ComposeProjectException("Compose service {$name}: extends nested too deeply.");
            }

            $other = $this->raw($extRel);
            unset($other['include']);
            // Paths of the extended file resolve against its own directory.
            self::rebaseDoc($other, self::dir($extRel));

            if (! array_key_exists($target, (array) ($other['services'] ?? []))) {
                throw new ComposeProjectException("Compose service {$name}: extends unknown service {$target} in {$extRel}.");
            }

            $parent = $this->extend($extRel, $other, $target, $depth + 1, $seen);
        }

        // depends_on, links and volumes_from are never inherited (Compose spec).
        unset($parent['depends_on'], $parent['links'], $parent['volumes_from']);

        return self::mergeService($parent, $service);
    }

    // ---- paths --------------------------------------------------------------------------------------------------

    public static function clean(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' && $out !== [] && end($out) !== '..') {
                array_pop($out);
            } else {
                $out[] = $segment;
            }
        }

        return $out === [] ? '.' : implode('/', $out);
    }

    private static function dir(string $path): string
    {
        $dir = dirname($path);

        return $dir === '' ? '.' : $dir;
    }

    /** $path (relative to the repository directory $dir) as a clean root-relative path inside the repository. */
    private static function repoPath(string $dir, string $path, string $what): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '~')) {
            throw new ComposeProjectException("{$what}: must be a path inside the repository.");
        }

        $clean = self::clean($dir.'/'.$path);

        if ($clean === '..' || str_starts_with($clean, '../')) {
            throw new ComposeProjectException("{$what}: leaves the repository.");
        }

        return $clean;
    }

    /** A path relative to $base as "./<root-relative>" ("." for the root); absolute/home/interpolated paths unchanged. */
    private static function rebased(string $base, string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '~') || str_contains($path, '$')) {
            return $path;
        }

        $clean = self::clean($base.'/'.$path);

        return match (true) {
            $clean === '.' => '.',
            $clean === '..' || str_starts_with($clean, '../') => $clean,
            default => './'.$clean,
        };
    }

    private static function isBindSource(string $source): bool
    {
        return str_starts_with($source, '.') || str_starts_with($source, '/') || str_starts_with($source, '~') || str_contains($source, '/');
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function rebaseDoc(array &$doc, string $base): void
    {
        foreach ((array) ($doc['services'] ?? []) as $name => $service) {
            if (is_array($service)) {
                $doc['services'][$name] = self::rebaseService($service, $base);
            }
        }

        foreach (['configs', 'secrets'] as $kind) {
            foreach ((array) ($doc[$kind] ?? []) as $name => $item) {
                if (is_array($item) && is_string($item['file'] ?? null)) {
                    $doc[$kind][$name]['file'] = self::rebased($base, $item['file']);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function rebaseService(array $service, string $base): array
    {
        if (is_string($service['build'] ?? null)) {
            $service['build'] = self::rebased($base, $service['build']);
        } elseif (is_array($service['build'] ?? null)) {
            $context = is_string($service['build']['context'] ?? null) && $service['build']['context'] !== '' ? $service['build']['context'] : '.';

            if (! str_contains($context, '://') && ! str_starts_with($context, 'git@')) {
                $service['build']['context'] = self::rebased($base, $context);
            }
        }

        if (is_array($service['volumes'] ?? null)) {
            foreach ($service['volumes'] as $i => $volume) {
                if (is_string($volume)) {
                    $parts = explode(':', $volume, 2);

                    if (count($parts) === 2 && self::isBindSource($parts[0])) {
                        $service['volumes'][$i] = self::rebased($base, $parts[0]).':'.$parts[1];
                    }
                } elseif (is_array($volume) && ($volume['type'] ?? null) === 'bind' && is_string($volume['source'] ?? null)) {
                    $service['volumes'][$i]['source'] = self::rebased($base, $volume['source']);
                }
            }
        }

        if (is_string($service['env_file'] ?? null)) {
            $service['env_file'] = self::rebased($base, $service['env_file']);
        } elseif (is_array($service['env_file'] ?? null)) {
            foreach ($service['env_file'] as $i => $env) {
                if (is_string($env)) {
                    $service['env_file'][$i] = self::rebased($base, $env);
                } elseif (is_array($env) && is_string($env['path'] ?? null)) {
                    $service['env_file'][$i]['path'] = self::rebased($base, $env['path']);
                }
            }
        }

        return $service;
    }

    // ---- merging --------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private static function mergeDocs(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if ($key === 'services') {
                $services = is_array($base['services'] ?? null) ? $base['services'] : [];

                foreach ((array) $value as $name => $service) {
                    $service = is_array($service) ? $service : [];
                    $services[$name] = is_array($services[$name] ?? null) ? self::mergeService($services[$name], $service) : $service;
                }

                $base['services'] = $services;
            } elseif (in_array($key, ['volumes', 'networks', 'configs', 'secrets'], true)) {
                $base[$key] = self::mergeNested(is_array($base[$key] ?? null) ? $base[$key] : [], is_array($value) ? $value : []);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private static function mergeService(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $key = (string) $key;

            if (! array_key_exists($key, $base) || $value === null) {
                $base[$key] = $value;
            } elseif (in_array($key, self::MAPPING_KEYS, true)) {
                $base[$key] = self::mergeNested(self::mapping($key, $base[$key]), self::mapping($key, $value));
            } elseif ($key === 'volumes') {
                $base[$key] = self::mergeVolumes(self::asList($base[$key]), self::asList($value));
            } elseif ($key === 'build') {
                $base[$key] = self::mergeNested(self::buildMap($base[$key]), self::buildMap($value));
            } elseif (in_array($key, ['command', 'entrypoint', 'profiles'], true)) {
                $base[$key] = $value;
            } elseif (in_array($key, self::APPEND_KEYS, true)) {
                $base[$key] = self::appendUnique(self::asList($base[$key]), self::asList($value));
            } elseif (self::isMap($base[$key]) && self::isMap($value)) {
                $base[$key] = self::mergeNested($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Mappings merge recursively; sequences and scalars are replaced.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private static function mergeNested(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = self::isMap($base[$key] ?? null) && self::isMap($value) ? self::mergeNested($base[$key], $value) : $value;
        }

        return $base;
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        return match (true) {
            $value === null => [],
            is_array($value) && array_is_list($value) => $value,
            default => [$value],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildMap(mixed $value): array
    {
        return is_string($value) ? ['context' => $value] : (is_array($value) ? $value : []);
    }

    /**
     * The list form of environment/labels/… ("K=V", or names for depends_on/networks) as a mapping.
     *
     * @return array<string, mixed>
     */
    private static function mapping(string $key, mixed $value): array
    {
        if (! is_array($value) || self::isMap($value)) {
            return is_array($value) ? $value : [];
        }

        $out = [];

        foreach ($value as $item) {
            $item = (string) $item;

            if ($key === 'depends_on') {
                $out[$item] = ['condition' => 'service_started'];
            } elseif ($key === 'networks') {
                $out[$item] = null;
            } elseif ($key === 'extra_hosts') {
                [$host, $ip] = array_pad(preg_split('/[:=]/', $item, 2) ?: [$item], 2, '');
                $out[$host] = $ip;
            } else {
                [$k, $v] = array_pad(explode('=', $item, 2), 2, null);
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private static function volumeTarget(mixed $volume): string
    {
        if (is_string($volume)) {
            $parts = explode(':', $volume);

            return count($parts) === 1 ? $parts[0] : $parts[1];
        }

        return is_array($volume) ? (string) ($volume['target'] ?? '') : (string) json_encode($volume);
    }

    /**
     * One mount per container path; the later file wins.
     *
     * @param  list<mixed>  $base
     * @param  list<mixed>  $over
     * @return list<mixed>
     */
    private static function mergeVolumes(array $base, array $over): array
    {
        $out = [];
        $index = [];

        foreach ([...$base, ...$over] as $volume) {
            $target = self::volumeTarget($volume);

            if (isset($index[$target])) {
                $out[$index[$target]] = $volume;

                continue;
            }

            $index[$target] = count($out);
            $out[] = $volume;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $base
     * @param  list<mixed>  $over
     * @return list<mixed>
     */
    private static function appendUnique(array $base, array $over): array
    {
        $out = [];
        $seen = [];

        foreach ([...$base, ...$over] as $value) {
            $key = (string) json_encode($value);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Drop services of inactive profiles and depends_on entries pointing at them; `profiles` is removed from the
     * services kept (the servers run `compose up` without --profile).
     *
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $active
     */
    private static function applyProfiles(array &$doc, array $active): void
    {
        $services = is_array($doc['services'] ?? null) ? $doc['services'] : [];

        foreach ($services as $name => $service) {
            $profiles = array_map('strval', self::asList($service['profiles'] ?? null));

            if ($profiles === []) {
                continue;
            }

            if (array_intersect($profiles, $active) !== []) {
                unset($services[$name]['profiles']);
            } else {
                unset($services[$name]);
            }
        }

        foreach ($services as $name => $service) {
            if (! array_key_exists('depends_on', $service)) {
                continue;
            }

            $deps = self::mapping('depends_on', $service['depends_on']);
            $kept = array_intersect_key($deps, $services);

            if (count($kept) === count($deps)) {
                continue;
            }

            if ($kept === []) {
                unset($services[$name]['depends_on']);
            } else {
                $services[$name]['depends_on'] = $kept;
            }
        }

        if (array_key_exists('services', $doc)) {
            $doc['services'] = $services;
        }
    }
}
