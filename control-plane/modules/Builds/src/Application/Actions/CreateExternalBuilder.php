<?php

namespace Kiln\Builds\Application\Actions;

use Kiln\Builds\Domain\Models\Builder;
use Kiln\Identity\Contracts\AuditLog;

/**
 * A builder the organization runs itself (`kiln-builder serve --url … --token …`). Returns the plain
 * token, which is shown once.
 */
final class CreateExternalBuilder
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  list<string>  $modes
     * @return array{0: Builder, 1: string}
     */
    public function __invoke(string $organizationId, string $name, array $modes, ?string $actorId = null): array
    {
        $token = Builder::newToken();
        $builder = Builder::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'kind' => Builder::KIND_EXTERNAL,
            'token_hash' => Builder::hashToken($token),
            'modes' => array_values(array_intersect(['native', 'docker'], $modes)),
            'enabled' => true,
        ]);

        $this->audit->record('builds.builder_created', 'builder', $builder->id, ['name' => $name], $organizationId, $actorId);

        return [$builder, $token];
    }
}
