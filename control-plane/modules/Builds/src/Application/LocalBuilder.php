<?php

namespace Falak\Builds\Application;

use Falak\Builds\Domain\Models\Builder;

/**
 * The shared builder on the control-plane host, authenticated with FALAK_LOCAL_BUILDER_TOKEN.
 * Its row is created on first poll and follows the configured token.
 */
final class LocalBuilder
{
    public function matches(string $token): bool
    {
        $configured = (string) config('builds.local_builder.token');

        return $configured !== '' && hash_equals($configured, $token);
    }

    public function resolve(string $token): Builder
    {
        $builder = Builder::query()->where('kind', Builder::KIND_LOCAL)->first() ?? new Builder(['kind' => Builder::KIND_LOCAL]);

        $builder->fill([
            'organization_id' => null,
            'name' => (string) config('builds.local_builder.name', 'control-plane'),
            'token_hash' => Builder::hashToken($token),
            'modes' => (array) config('builds.local_builder.modes', ['native', 'docker']),
        ]);

        if (! $builder->exists) {
            $builder->enabled = true;
        }

        $builder->save();

        return $builder;
    }
}
