<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * The read-only file browser: one page of a directory of the volume, or a name search below it (volume.browse; the
 * agent confines every path to the volume and never follows symlinks). Audited. Database volumes are never browsable:
 * their data is read through backups.
 */
final class BrowseVolume
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly SiteDirectory $sites,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{path: string, entries: list<array{name: string, path: string, type: string, size: int, mtime: string}>, total: int, truncated: bool}
     *
     * @throws ValidationException
     */
    public function __invoke(Volume $volume, string $path = '', ?string $search = null, int $offset = 0, int $limit = 200): array
    {
        $serverId = $this->serverOf($volume);
        $path = self::relative($path);

        $result = $this->commands->ask($serverId, 'volume.browse', array_filter([
            'volume' => $volume->ref(),
            'path' => $path,
            'search' => $search !== null && $search !== '' ? mb_substr($search, 0, 128) : null,
            'offset' => max(0, $offset),
            'limit' => max(1, min(1000, $limit)),
        ], fn ($v) => $v !== null));

        $this->audit->record('volumes.browsed', 'volume', $volume->id, array_filter(['name' => $volume->name, 'path' => $path, 'search' => $search]), $volume->organization_id);

        return [
            'path' => (string) ($result['path'] ?? $path),
            'entries' => array_values((array) ($result['entries'] ?? [])),
            'total' => (int) ($result['total'] ?? 0),
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    /**
     * Where the volume's files are read: its server, or for a shared path the leader of its site.
     *
     * @throws ValidationException
     */
    public function serverOf(Volume $volume): string
    {
        if ($volume->attachments->contains(fn ($attachment) => $attachment->attachable_type === AttachableType::Database)) {
            throw ValidationException::withMessages(['volume' => 'Database volumes are not browsable: download a backup instead.']);
        }

        if ($volume->status !== VolumeStatus::Active && $volume->kind !== VolumeKind::SharedPath) {
            throw ValidationException::withMessages(['volume' => "The volume is {$volume->status->value}."]);
        }

        if ($volume->kind === VolumeKind::SharedPath && $volume->sharedFile()) {
            throw ValidationException::withMessages(['volume' => 'This shared path is a single file.']);
        }

        $serverId = $volume->server_id;

        if ($serverId === null) {
            $siteId = $volume->attachments->first()?->attachable_id;
            $serverId = $siteId !== null ? $this->sites->leader($siteId)?->serverId : null;
        }

        return $serverId ?? throw ValidationException::withMessages(['volume' => 'The volume is on no server.']);
    }

    /**
     * A path relative to the volume's root, without "." or ".." segments.
     *
     * @throws ValidationException
     */
    public static function relative(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);

        if (strlen($path) > 4096 || str_contains($path, "\0") || array_intersect($segments, ['', '.', '..']) !== []) {
            throw ValidationException::withMessages(['path' => 'Invalid path.']);
        }

        return $path;
    }
}
