<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\InstancePorts;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A database container whose server is gone comes back on another server: the same instance (id, name, DNS name,
 * password, settings, limits), its databases, users, grants and schedules move with it; it gets a new data volume
 * (as large as the lost one) and host port there, and db.instance.create recreates the container, then its databases
 * and users, empty (HandleCommandOutcome). The data comes from backups afterwards (RestoreBackup). Nothing is sent to
 * the lost server; its volume row stays until the server is deleted.
 */
final class RelocateInstance
{
    public function __construct(
        private readonly CreateInstance $create,
        private readonly CreateDatabase $createDatabase,
        private readonly ApplyDatabaseUser $applyUser,
        private readonly ServerDirectory $servers,
        private readonly ServiceVolumes $volumes,
        private readonly InstancePorts $ports,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    /**
     * @param  bool  $suspendPitr  turn PITR shipping off on the new, empty container: its fresh log must never join the
     *                             instance's recovery history (a PITR restore to the latest point follows, then PITR is
     *                             turned on again for the restored instance)
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, string $targetServerId, ?string $actorId = null, bool $suspendPitr = false): DatabaseInstance
    {
        if (in_array($instance->status, [InstanceStatus::Retired, InstanceStatus::Deleting], true)) {
            throw ValidationException::withMessages(['database_instance_id' => "{$instance->name} is {$instance->status->value}."]);
        }

        $target = $this->servers->find(strtolower($targetServerId));

        if ($target === null || $target->organizationId !== $instance->organization_id) {
            throw ValidationException::withMessages(['server_id' => 'Choose a server of this organization.']);
        }

        if ($target->id === $instance->server_id) {
            return $this->retry($instance, $target->name);
        }

        if (! $target->isActive()) {
            throw ValidationException::withMessages(['server_id' => "{$target->name} is {$target->status->label()}: wait until it is active."]);
        }

        if (DatabaseInstance::query()->where('server_id', $target->id)->where('name', $instance->name)->whereNot('status', InstanceStatus::Retired)->exists()) {
            throw ValidationException::withMessages(['server_id' => "{$target->name} already has a database named {$instance->name}."]);
        }

        $from = $instance->server_id;

        DB::transaction(function () use ($instance, $target, $actorId, $suspendPitr) {
            // Serializes port allocation on the target server.
            DatabaseInstance::query()->where('server_id', $target->id)->lockForUpdate()->get(['id']);
            $engine = $instance->engine;
            $old = $instance->volume_id !== null ? $this->volumes->find($instance->volume_id) : null;
            $disk = max($engine->defaultDisk(), (int) ($old?->sizeLimitBytes ?? 0));

            $instance->forceFill([
                'server_id' => $target->id,
                'server_name' => $target->name,
                'host_port' => $this->ports->allocate($target->id, $this->servers->takenPorts($target->id)),
                'image_digest' => $engine->pinnedDigest($instance->version) ?? $instance->image_digest,
                'published_addresses' => null,
                'pending_published_addresses' => null,
                'network_command_id' => null,
                'firewall_sources' => null,
                'health' => null,
                'health_at' => null,
                'next_root_password' => null,
                ...($suspendPitr ? ['pitr_enabled' => false] : []),
                'status' => InstanceStatus::Pending,
                'status_message' => "Recovering on {$target->name}: creating the container.",
            ])->save();

            $databases = $instance->databases()->get();

            foreach ($databases as $database) {
                $this->volumes->releaseDatabase($database->id);
                $database->forceFill(['server_id' => $target->id, 'status' => ResourceStatus::Pending, 'status_message' => null, 'command_id' => null])->save();
            }

            $instance->users()->update(['server_id' => $target->id, 'status' => ResourceStatus::Pending, 'status_message' => null]);

            $volume = $this->volumes->createSized($instance->organization_id, $target->id, CreateInstance::volumeName($instance), $disk, ['db-instance' => $instance->id], protected: true, actorId: $actorId);

            if (($primary = $databases->sortBy('created_at')->first()) !== null) {
                $this->volumes->attach($volume->id, AttachableType::Database, $primary->id, '/var/lib/falak/db');
            }

            $instance->forceFill(['volume_id' => $volume->id])->save();

            $this->create->dispatch($instance);
        });

        $this->audit->record('databases.instance_relocated', 'database_instance', $instance->id, ['name' => $instance->name, 'from' => $from, 'to' => $target->id], $instance->organization_id);

        return $instance;
    }

    /**
     * A relocation already moved it here and something failed: the container is created again (it never came up), or
     * the databases and users that failed are (the container runs). One still in progress is left alone.
     */
    private function retry(DatabaseInstance $instance, string $serverName): DatabaseInstance
    {
        if ($instance->status === InstanceStatus::Failed) {
            $instance->forceFill(['status' => InstanceStatus::Pending, 'status_message' => "Recovering on {$serverName}: creating the container."])->save();
            $instance->databases()->whereIn('status', [ResourceStatus::Failed, ResourceStatus::Pending])->update(['status' => ResourceStatus::Pending, 'status_message' => null, 'command_id' => null]);
            $instance->users()->whereIn('status', [ResourceStatus::Failed, ResourceStatus::Pending])->update(['status' => ResourceStatus::Pending, 'status_message' => null]);
            $this->create->dispatch($instance);

            return $instance;
        }

        if ($instance->status !== InstanceStatus::Active) {
            return $instance;
        }

        foreach ($instance->databases()->where('status', ResourceStatus::Failed)->get() as $database) {
            $database->forceFill(['status' => ResourceStatus::Pending, 'status_message' => null])->save();
            $instance->engine->isKeyValue()
                ? $database->forceFill(['status' => ResourceStatus::Active])->save()
                : $this->createDatabase->dispatch($database);
        }

        $instance->users()->where('status', ResourceStatus::Failed)->get()->each(fn (DatabaseUser $user) => ($this->applyUser)($user, background: true));

        return $instance;
    }
}
