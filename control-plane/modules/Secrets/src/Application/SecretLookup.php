<?php

namespace Falak\Secrets\Application;

use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which secret a name means for a scope chain: the one in the nearest scope.
 */
final class SecretLookup
{
    /**
     * @param  list<string>  $names
     * @return array<string, Secret> name => nearest secret (names with none are absent)
     */
    public function nearest(ScopeChain $chain, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $links = $chain->links();

        $candidates = Secret::query()
            ->where('organization_id', $chain->organizationId)
            ->whereIn('name', array_values(array_unique($names)))
            ->where(function (Builder $query) use ($links) {
                foreach ($links as [$scope, $id]) {
                    $query->orWhere(fn (Builder $q) => $q->where('scope_type', $scope->value)->where('scope_id', strtolower($id)));
                }
            })
            ->get();

        $nearest = [];

        foreach ($candidates as $secret) {
            $current = $nearest[$secret->name] ?? null;

            if ($current === null || $secret->scope_type->distance() < $current->scope_type->distance()) {
                $nearest[$secret->name] = $secret;
            }
        }

        return $nearest;
    }
}
