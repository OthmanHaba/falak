<?php

namespace Falak\Projects\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Group;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * Put services of one environment into a new canvas group (UI_DESIGN §4.3). The group's anchor is the top-left of the
 * services' positions; their positions become relative to it, so nothing moves on screen. Services already in another
 * group move over (that group is dropped when it empties). Compose sites are groups of their own and can't be nested.
 */
final class GroupServices
{
    public function __construct(
        private readonly MoveService $move,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @param  list<string>  $serviceIds  projects_services ids
     *
     * @throws ValidationException
     */
    public function __invoke(Environment $environment, string $name, array $serviceIds): Group
    {
        $services = Service::query()->where('environment_id', $environment->id)->whereIn('id', array_map('strtolower', $serviceIds))->with('group')->get();

        if ($services->isEmpty() || $services->count() !== count(array_unique($serviceIds))) {
            throw ValidationException::withMessages(['service_ids' => 'Pick services of this environment.']);
        }

        if ($services->contains(fn (Service $service) => $service->kind === ServiceKind::Site && $this->sites->find($service->ref_id)?->compose !== null)) {
            throw ValidationException::withMessages(['service_ids' => 'Compose services are already grouped by their site.']);
        }

        return DB::transaction(function () use ($environment, $name, $services) {
            $positions = $services->mapWithKeys(fn (Service $service) => [$service->id => MoveService::absolute($service)]);

            $group = Group::query()->create([
                'organization_id' => $environment->organization_id,
                'project_id' => $environment->project_id,
                'environment_id' => $environment->id,
                'name' => trim($name) !== '' ? trim($name) : 'Group',
                'x' => (int) $positions->min('x'),
                'y' => (int) $positions->min('y'),
            ]);

            foreach ($services as $service) {
                $position = $positions[$service->id];
                ($this->move)($service, $position['x'] - $group->x, $position['y'] - $group->y, $group);
            }

            return $group->refresh();
        });
    }
}
