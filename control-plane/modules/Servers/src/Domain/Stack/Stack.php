<?php

namespace Kiln\Servers\Domain\Stack;

use Kiln\Servers\Contracts\ServerType;

/**
 * Software selected for a server at creation. PHP versions installed later are tracked in
 * servers_php_versions; the stack keeps the runtime choice and the other components.
 */
final readonly class Stack
{
    public const PHP_RUNTIMES = ['frankenphp', 'fpm'];

    /**
     * @param  list<string>  $phpVersions
     */
    public function __construct(
        public ?string $phpRuntime = null,
        public array $phpVersions = [],
        public ?string $phpDefault = null,
        public ?string $node = null,
        public ?string $database = null,
        public ?string $cache = null,
        public bool $docker = false,
    ) {}

    public static function defaultsFor(ServerType $type): self
    {
        $php = (string) config('servers.default_php', '8.4');
        $node = (string) config('servers.default_node', '22');

        return match ($type) {
            ServerType::App => new self('frankenphp', [$php], $php, $node, 'postgresql', 'redis'),
            ServerType::Web, ServerType::Worker => new self('frankenphp', [$php], $php, $node),
            ServerType::Database => new self(database: 'postgresql'),
            ServerType::Cache => new self(cache: 'redis'),
            ServerType::LoadBalancer => new self,
            ServerType::Builder => new self(node: $node, docker: true),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $php = is_array($data['php'] ?? null) ? $data['php'] : null;
        $versions = array_values(array_unique(array_map('strval', (array) ($php['versions'] ?? []))));

        return new self(
            phpRuntime: $php ? (string) ($php['runtime'] ?? 'frankenphp') : null,
            phpVersions: $versions,
            phpDefault: $php ? (string) ($php['default'] ?? ($versions[0] ?? '')) ?: null : null,
            node: isset($data['node']) && $data['node'] !== '' ? (string) $data['node'] : null,
            database: isset($data['database']) && $data['database'] !== '' ? (string) $data['database'] : null,
            cache: isset($data['cache']) && $data['cache'] !== '' ? (string) $data['cache'] : null,
            docker: (bool) ($data['docker'] ?? false),
        );
    }

    /**
     * @return array{php: array{runtime: string, versions: list<string>, default: ?string}|null, node: ?string, database: ?string, cache: ?string, docker: bool}
     */
    public function toArray(): array
    {
        return [
            'php' => $this->phpRuntime !== null ? ['runtime' => $this->phpRuntime, 'versions' => $this->phpVersions, 'default' => $this->phpDefault] : null,
            'node' => $this->node,
            'database' => $this->database,
            'cache' => $this->cache,
            'docker' => $this->docker,
        ];
    }

    public function withDatabase(?string $engine): self
    {
        return new self($this->phpRuntime, $this->phpVersions, $this->phpDefault, $this->node, $engine, $this->cache, $this->docker);
    }

    public function withCache(?string $engine): self
    {
        return new self($this->phpRuntime, $this->phpVersions, $this->phpDefault, $this->node, $this->database, $engine, $this->docker);
    }

    public function withPhp(string $runtime, array $versions, ?string $default): self
    {
        return new self($runtime, array_values($versions), $default, $this->node, $this->database, $this->cache, $this->docker);
    }

    /**
     * @return array<string, string> field => error message (empty = valid)
     */
    public function errorsFor(ServerType $type): array
    {
        $allowed = $type->allowedComponents();
        $errors = [];

        $present = array_filter([
            'php' => $this->phpRuntime !== null,
            'node' => $this->node !== null,
            'database' => $this->database !== null,
            'cache' => $this->cache !== null,
            'docker' => $this->docker,
        ]);

        foreach (array_keys($present) as $component) {
            if (! in_array($component, $allowed, true)) {
                $errors["stack.{$component}"] = "A {$type->label()} cannot include {$component}.";
            }
        }

        if ($this->phpRuntime !== null) {
            $offered = (array) config('servers.php_versions', []);

            if (! in_array($this->phpRuntime, self::PHP_RUNTIMES, true)) {
                $errors['stack.php.runtime'] = 'Choose FrankenPHP or PHP-FPM.';
            }

            if ($this->phpVersions === [] || array_diff($this->phpVersions, $offered) !== []) {
                $errors['stack.php.versions'] = 'Choose one or more supported PHP versions.';
            }

            if ($this->phpDefault === null || ! in_array($this->phpDefault, $this->phpVersions, true)) {
                $errors['stack.php.default'] = 'The default PHP version must be one of the selected versions.';
            }
        }

        if ($this->node !== null && ! array_key_exists($this->node, (array) config('servers.node_versions', []))) {
            $errors['stack.node'] = 'Unsupported Node.js version.';
        }

        if ($this->database !== null && ! array_key_exists($this->database, (array) config('servers.databases', []))) {
            $errors['stack.database'] = 'Unsupported database engine.';
        }

        if ($this->cache !== null && ! array_key_exists($this->cache, (array) config('servers.caches', []))) {
            $errors['stack.cache'] = 'Unsupported cache engine.';
        }

        if ($type === ServerType::Database && $this->database === null) {
            $errors['stack.database'] = 'A database server needs a database engine.';
        }

        if ($type === ServerType::Cache && $this->cache === null) {
            $errors['stack.cache'] = 'A cache server needs Redis or Valkey.';
        }

        if ($type === ServerType::Builder && ! $this->docker) {
            $errors['stack.docker'] = 'Builders require Docker.';
        }

        return $errors;
    }
}
