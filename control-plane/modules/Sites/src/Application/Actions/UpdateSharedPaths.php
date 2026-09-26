<?php

namespace Kiln\Sites\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SharedPath;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteUpdated;

final class UpdateSharedPaths
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  list<array{path: string, type: string}>  $paths
     */
    public function __invoke(Site $site, array $paths): void
    {
        $normalized = [];

        foreach ($paths as $index => $path) {
            $relative = trim(str_replace('\\', '/', $path['path']), '/');

            if ($relative === '' || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1 || preg_match('#^[A-Za-z0-9._@+/-]+$#', $relative) !== 1) {
                throw ValidationException::withMessages(["paths.{$index}.path" => 'Use a relative path inside the release (letters, digits, . _ - / @ +).']);
            }

            $normalized[$relative] = new SharedPath($relative, $path['type'] === 'file' ? 'file' : 'directory');
        }

        $site->forceFill(['shared_paths' => array_values($normalized)])->save();

        $this->audit->record('site.shared_paths_updated', 'site', $site->id, ['paths' => array_keys($normalized)], $site->organization_id);
        SiteUpdated::dispatch($site->id, $site->organization_id, ['shared_paths'], $site->serverIds());
    }
}
