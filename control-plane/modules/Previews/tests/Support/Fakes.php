<?php

namespace Falak\Previews\Tests\Support;

use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Edge\Contracts\Data\PreviewDomainData;
use Falak\Edge\Contracts\PreviewDomains;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Preview domain double: records routes, auth and released sites. */
final class FakePreviewDomains implements PreviewDomains
{
    public ?PreviewDomainData $settings = null;

    /** @var array<string, string> host => site id */
    public array $routes = [];

    /** @var array<string, array{username: string, password: string}> site id => credentials */
    public array $protected = [];

    /** @var list<string> */
    public array $released = [];

    public function __construct(string $domain = 'prv.example.com', string $organizationId = 'org')
    {
        $this->settings = new PreviewDomainData($domain, $organizationId, 'server', 'cred', true, 'active', null);
    }

    public function settings(): ?PreviewDomainData
    {
        return $this->settings;
    }

    public function credentials(string $organizationId): array
    {
        return [];
    }

    public function configure(string $organizationId, string $domain, ?string $dnsCredentialId, string $serverId, ?string $actorId = null): PreviewDomainData
    {
        return $this->settings = new PreviewDomainData($domain, $organizationId, $serverId, $dnsCredentialId, $dnsCredentialId !== null, $dnsCredentialId !== null ? 'active' : 'manual', null);
    }

    public function clear(): void
    {
        $this->settings = null;
    }

    public function route(string $siteId, string $host, ?string $service = null): void
    {
        if (isset($this->routes[$host])) {
            throw ValidationException::withMessages(['domain' => 'This domain is already in use.']);
        }

        $this->routes[$host] = $siteId;
    }

    public function release(string $siteId): void
    {
        $this->released[] = $siteId;
        $this->routes = array_filter($this->routes, fn (string $id) => $id !== $siteId);
    }

    public function available(string $host): bool
    {
        return ! isset($this->routes[$host]);
    }

    public function protect(string $siteId, string $username, #[\SensitiveParameter] string $password): void
    {
        $this->protected[$siteId] = compact('username', 'password');
    }
}

/** Database double: databases are pending until the test announces them; restores and scripts are recorded. */
final class FakeDatabaseProvisioner implements DatabaseProvisioner
{
    /** @var array<string, array{organization: string, server: string, engine: string, name: string, options: array<string, mixed>}> */
    public array $created = [];

    /** @var list<array{source: string, target: string, id: string}> */
    public array $restores = [];

    /** @var list<array{database: string, kind: string, script: string, key: string}> */
    public array $scripts = [];

    /** @var list<array{id: string, volume: bool}> */
    public array $deleted = [];

    public bool $noBackup = false;

    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null, array $options = []): DatabaseData
    {
        $id = strtolower((string) Str::ulid());
        $this->created[$id] = ['organization' => $organizationId, 'server' => $serverId, 'engine' => $engine, 'name' => $name, 'options' => $options];

        return new DatabaseData($id, $organizationId, $serverId, (string) ($options['database'] ?? $name), $engine, $options['version'] ?? null, 5432, 'pending', null);
    }

    public function delete(string $databaseId, bool $deleteVolume = false): void
    {
        $this->deleted[] = ['id' => $databaseId, 'volume' => $deleteVolume];
    }

    public function restoreLatestBackup(string $sourceDatabaseId, string $targetDatabaseId, ?string $actorId = null): string
    {
        if ($this->noBackup) {
            throw ValidationException::withMessages(['backup' => 'The source database has no successful backup.']);
        }

        $id = strtolower((string) Str::ulid());
        $this->restores[] = ['source' => $sourceDatabaseId, 'target' => $targetDatabaseId, 'id' => $id];

        return $id;
    }

    public function runScript(string $databaseId, string $kind, string $script, string $key, int $timeout = 900): string
    {
        $this->scripts[] = ['database' => $databaseId, 'kind' => $kind, 'script' => $script, 'key' => 'databases.script:'.$key];

        return strtolower((string) Str::ulid());
    }
}

final class FakeDeploymentTrigger implements DeploymentTrigger
{
    /** @var list<array{site_id: string, commit: ?string, requested_by: ?string}> */
    public array $deployed = [];

    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
    {
        $this->deployed[] = ['site_id' => $siteId, 'commit' => $commit, 'requested_by' => $requestedBy];

        return strtolower((string) Str::ulid());
    }
}
