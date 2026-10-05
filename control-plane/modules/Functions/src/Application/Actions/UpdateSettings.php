<?php

namespace Falak\Functions\Application\Actions;

use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Scaling: instances, concurrency, idle timeout and limits. A live function is redeployed (same code) so
 * its servers pick them up.
 */
final class UpdateSettings
{
    public const FIELDS = ['min_instances', 'max_instances', 'concurrency', 'idle_timeout_s', 'memory_mb', 'cpus', 'request_timeout_s'];

    public function __construct(
        private readonly DeploymentDirectory $directory,
        private readonly DeploymentTrigger $deployments,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach ((array) config('functions.bounds') as $field => [$min, $max]) {
            $rules[$field] = ['sometimes', $field === 'cpus' ? 'numeric' : 'integer', "between:{$min},{$max}"];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data  validated with rules()
     * @return ?string the redeployment id
     *
     * @throws ValidationException
     */
    public function __invoke(SiteData $site, CloudFunction $function, array $data, ?string $userId): ?string
    {
        $function->fill(array_intersect_key($data, array_flip(self::FIELDS)));

        if ($function->max_instances < max(1, $function->min_instances)) {
            throw ValidationException::withMessages(['max_instances' => 'Max instances must be at least min instances (and at least 1).']);
        }

        if (! $function->isDirty()) {
            return null;
        }

        $changes = array_map(fn ($field) => ['from' => $function->getOriginal($field), 'to' => $function->getAttribute($field)], array_combine(array_keys($function->getDirty()), array_keys($function->getDirty())));
        $function->save();
        $this->audit->record('function.settings.updated', 'site', $site->id, ['changes' => $changes], $site->organizationId);

        $live = $this->directory->liveCommit($site->id);

        if ($live === null) {
            return null;
        }

        try {
            return $this->deployments->deploy($site->id, $userId, $live, 'Apply scaling settings');
        } catch (ValidationException) {
            return null;
        }
    }
}
