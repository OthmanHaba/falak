<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A new docker, sized or bind volume on a server: the row is pending until volume.create succeeds there. Shared paths
 * come from a site's settings ({@see SyncSharedPaths}); restores and clones create their target with the command.
 */
final class CreateVolume
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, string>  $labels
     *
     * @throws ValidationException
     */
    public function __invoke(
        string $organizationId,
        string $serverId,
        string $name,
        VolumeKind $kind,
        ?int $sizeBytes = null,
        ?string $hostPath = null,
        array $labels = [],
        bool $protected = false,
        ?string $actorId = null,
    ): Volume {
        $volume = $this->prepare($organizationId, $serverId, $name, $kind, $sizeBytes, $hostPath, $labels, $protected, $actorId);
        self::save($volume);

        $handle = $this->commands->tryDispatch($serverId, 'volume.create', AgentCommands::createPayload($volume), "volume.create:{$volume->id}");
        $volume->forceFill($handle !== null
            ? ['command_id' => $handle->id]
            : ['status' => VolumeStatus::Failed, 'status_message' => AgentCommands::NOT_CONNECTED])->save();

        $this->audit->record('volumes.created', 'volume', $volume->id, ['name' => $volume->name, 'kind' => $kind->value, 'server_id' => $serverId], $organizationId);

        return $volume;
    }

    /**
     * A validated, unsaved, pending volume (restores and clones create their target with their own command).
     *
     * @param  array<string, string>  $labels
     *
     * @throws ValidationException
     */
    public function prepare(
        string $organizationId,
        string $serverId,
        string $name,
        VolumeKind $kind,
        ?int $sizeBytes = null,
        ?string $hostPath = null,
        array $labels = [],
        bool $protected = false,
        ?string $actorId = null,
    ): Volume {
        $server = $this->servers->find($serverId);

        if ($server === null || $server->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['server_id' => 'Choose a server of this organization.']);
        }

        if ($kind === VolumeKind::SharedPath) {
            throw ValidationException::withMessages(['kind' => 'Shared paths are set in a site’s settings.']);
        }

        if ($kind === VolumeKind::Docker && ! $server->docker) {
            throw ValidationException::withMessages(['kind' => "{$server->name} has no Docker; choose a sized volume."]);
        }

        if (preg_match(Volume::NAME, $name) !== 1) {
            throw ValidationException::withMessages(['name' => 'Use lowercase letters, digits, ".", "_" and "-" (up to 63 characters).']);
        }

        if (Volume::query()->where('server_id', $serverId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "{$server->name} already has a volume named {$name}."]);
        }

        if ($kind === VolumeKind::Sized) {
            $min = (int) config('volumes.min_size_bytes');
            $max = (int) config('volumes.max_size_bytes');

            if ($sizeBytes === null || $sizeBytes < $min || $sizeBytes > $max) {
                throw ValidationException::withMessages(['size_bytes' => 'A sized volume holds between 16 MiB and 16 TiB.']);
            }
        }

        if ($kind === VolumeKind::Bind) {
            $hostPath = self::bindPath((string) $hostPath);
        }

        $volume = new Volume;
        $volume->id = strtolower((string) Str::ulid());

        return $volume->forceFill([
            'organization_id' => $organizationId,
            'server_id' => $serverId,
            'name' => $name,
            'kind' => $kind,
            // Falak's own Docker volumes are prefixed: never confused with a compose stack's <project>_<key>.
            'docker_name' => $kind === VolumeKind::Docker ? "falak-{$name}" : null,
            'host_path' => $kind === VolumeKind::Bind ? $hostPath : null,
            'size_limit_bytes' => $kind === VolumeKind::Sized ? $sizeBytes : null,
            'protected' => $protected,
            'labels' => $labels !== [] ? $labels : null,
            'status' => VolumeStatus::Pending,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Save a prepared volume; a volume of the same name created meanwhile on the server is a validation error.
     *
     * @throws ValidationException
     */
    public static function save(Volume $volume): void
    {
        try {
            $volume->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => "The server already has a volume named {$volume->name}."]);
        }
    }

    /**
     * An absolute, normalized host path inside one of the allowed directories (config volumes.bind_allow). The
     * directory itself is refused, only paths below it are allowed (the agent applies the same rule).
     *
     * @throws ValidationException
     */
    public static function bindPath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        if (! str_starts_with($path, '/') || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
            || in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
            throw ValidationException::withMessages(['host_path' => 'Use an absolute path without "." or ".." segments.']);
        }

        $path = '/'.implode('/', $segments);

        foreach ((array) config('volumes.bind_allow', []) as $allowed) {
            $allowed = rtrim((string) $allowed, '/');

            if ($allowed !== '' && str_starts_with($path.'/', $allowed.'/') && $path !== $allowed) {
                return $path;
            }
        }

        throw ValidationException::withMessages(['host_path' => 'Host paths must be inside a directory allowed for volumes (FALAK_VOLUME_BIND_ALLOW).']);
    }
}
