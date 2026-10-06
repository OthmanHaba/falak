<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * Protection and labels. The name is fixed: Docker and the agent know the volume by it (or by its id).
 */
final class UpdateVolume
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  ?array<string, string>  $labels  null leaves them
     *
     * @throws ValidationException
     */
    public function __invoke(Volume $volume, ?bool $protected = null, ?array $labels = null): void
    {
        $changes = array_filter(['protected' => $protected, 'labels' => $labels !== null ? ($labels ?: null) : null], fn ($v) => $v !== null);

        if ($labels === []) {
            $changes['labels'] = null;
        }

        $volume->forceFill($changes)->save();

        $this->audit->record('volumes.updated', 'volume', $volume->id, ['name' => $volume->name, ...array_map(fn ($v) => is_array($v) ? array_keys($v) : $v, $changes)], $volume->organization_id);
    }
}
