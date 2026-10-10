<?php

namespace Falak\Limits\Infrastructure;

use Falak\Limits\Contracts\ServiceHealth;
use Falak\Limits\Domain\Models\ServiceState;
use Illuminate\Database\Eloquent\Builder;

final class EloquentServiceHealth implements ServiceHealth
{
    public function badgesForSites(array $siteIds): array
    {
        return $this->badges(ServiceState::query()->whereIn('site_id', $siteIds), 'site_id');
    }

    public function badgesForInstances(array $instanceIds): array
    {
        return $this->badges(ServiceState::query()->where('service_kind', 'database')->whereIn('service_id', $instanceIds), 'service_id');
    }

    /**
     * @param  Builder<ServiceState>  $query
     * @return array<string, list<string>>
     */
    private function badges(Builder $query, string $key): array
    {
        $out = [];
        $recent = now()->subHours(max(1, (int) config('limits.oom_badge_hours', 24)));

        foreach ($query->where(fn (Builder $q) => $q->where('last_oom_at', '>', $recent)->orWhereNotNull('restart_loop_at'))->get() as $state) {
            foreach ($state->badges() as $badge) {
                $id = (string) $state->getAttribute($key);
                $out[$id] = array_values(array_unique([...($out[$id] ?? []), $badge]));
            }
        }

        return $out;
    }
}
