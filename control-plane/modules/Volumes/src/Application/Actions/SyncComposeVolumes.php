<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Sites\Contracts\ComposeInspector;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Facades\DB;

/**
 * A compose stack goes live on a server: each named volume of its (rendered) file is a docker volume of that server,
 * attached to the services that mount it. Compose names the Docker volume <project>_<key> unless the file sets `name:`
 * (or it is `external`: then its key); `external` volumes are recorded but never deleted by Falak. Volumes the file dropped keep their data, unattached.
 */
final class SyncComposeVolumes
{
    public function __construct(private readonly ComposeInspector $inspector) {}

    public function __invoke(string $organizationId, string $siteId, string $serverId, string $project, string $yaml): void
    {
        $summary = $this->inspector->parse($yaml);

        if (! $summary->valid()) {
            return;
        }

        /** @var array<string, list<array{service: string, target: string, read_only: bool}>> $mounts by volume key */
        $mounts = [];

        foreach ($summary->services as $service) {
            foreach ($service->namedMounts as $mount) {
                $mounts[$mount['volume']][] = ['service' => $service->name, 'target' => $mount['target'], 'read_only' => $mount['read_only']];
            }
        }

        DB::transaction(function () use ($organizationId, $siteId, $serverId, $project, $summary, $mounts) {
            $existing = Volume::query()
                ->where('server_id', $serverId)
                ->where('options->compose->site_id', $siteId)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Volume $volume) => (string) $volume->composeKey());

            foreach ($summary->volumeDefinitions + array_fill_keys($summary->volumes, ['name' => null, 'external' => false]) as $key => $definition) {
                $key = (string) $key;
                // External volumes are never prefixed with the project.
                $dockerName = $definition['name'] ?? ($definition['external'] ? $key : "{$project}_{$key}");

                if (preg_match(Volume::DOCKER_NAME, $dockerName) !== 1) {
                    continue;
                }

                $volume = $existing->get($key) ?? Volume::query()->create([
                    'organization_id' => $organizationId,
                    'server_id' => $serverId,
                    'name' => mb_substr("{$project}-{$key}", 0, 128),
                    'kind' => VolumeKind::Docker,
                    'docker_name' => $dockerName,
                    'options' => ['compose' => ['site_id' => $siteId, 'key' => $key], 'external' => (bool) $definition['external']],
                    // Compose creates it with the stack's `up`.
                    'status' => VolumeStatus::Active,
                ]);

                if ($volume->docker_name !== $dockerName || $volume->external() !== (bool) $definition['external']) {
                    $volume->forceFill(['docker_name' => $dockerName, 'options' => [...(array) $volume->options, 'external' => (bool) $definition['external']]])->save();
                }

                $this->attach($volume, $siteId, $mounts[$key] ?? []);
                $existing->forget($key);
            }

            // Dropped from the file: the data stays (Docker keeps the volume), the attachments go.
            foreach ($existing as $volume) {
                $volume->attachments()->delete();
            }
        });
    }

    /**
     * @param  list<array{service: string, target: string, read_only: bool}>  $mounts
     */
    private function attach(Volume $volume, string $siteId, array $mounts): void
    {
        $current = $volume->attachments()->get();
        $wanted = [];

        foreach ($mounts as $mount) {
            $wanted["{$mount['service']}\n{$mount['target']}"] = $mount;
        }

        foreach ($current as $attachment) {
            /** @var Attachment $attachment */
            $key = "{$attachment->service}\n{$attachment->mount_path}";

            if (! isset($wanted[$key])) {
                $attachment->delete();

                continue;
            }

            if ($attachment->read_only !== $wanted[$key]['read_only']) {
                $attachment->forceFill(['read_only' => $wanted[$key]['read_only']])->save();
            }

            unset($wanted[$key]);
        }

        foreach ($wanted as $mount) {
            $volume->attachments()->create([
                'attachable_type' => AttachableType::ComposeService,
                'attachable_id' => $siteId,
                'service' => mb_substr($mount['service'], 0, 128),
                'mount_path' => mb_substr($mount['target'], 0, 1024),
                'read_only' => $mount['read_only'],
            ]);
        }
    }
}
