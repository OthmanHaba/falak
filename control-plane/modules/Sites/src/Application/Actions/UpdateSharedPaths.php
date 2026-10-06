<?php

namespace Falak\Sites\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteUpdated;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Validation\ValidationException;

/**
 * A classic site's shared paths are volumes (kind shared_path) attached to it: paths added are attached, paths removed
 * detached (their files stay on the servers). The next deploy links them into the release.
 */
final class UpdateSharedPaths
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly ServiceVolumes $volumes,
    ) {}

    /**
     * @param  list<array{path: string, type: string}>  $paths
     */
    public function __invoke(Site $site, array $paths, ?string $actorId = null): void
    {
        $normalized = [];

        foreach ($paths as $index => $path) {
            $relative = trim(str_replace('\\', '/', $path['path']), '/');

            if ($relative === '' || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1 || preg_match('#^[A-Za-z0-9._@+/-]+$#', $relative) !== 1) {
                throw ValidationException::withMessages(["paths.{$index}.path" => 'Use a relative path inside the release (letters, digits, . _ - / @ +).']);
            }

            $normalized[$relative] = new SharedPath($relative, $path['type'] === 'file' ? 'file' : 'directory');
        }

        $this->volumes->syncSharedPaths($site->organization_id, $site->id, array_values($normalized), $actorId);

        $this->audit->record('site.shared_paths_updated', 'site', $site->id, ['paths' => array_keys($normalized)], $site->organization_id);
        SiteUpdated::dispatch($site->id, $site->organization_id, ['shared_paths'], $site->serverIds());
    }
}
