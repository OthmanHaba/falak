<?php

namespace Kiln\Databases\Application\Actions;

use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Network\Contracts\Firewalls;

/**
 * Lets the containers on a server (compose stacks, Docker sites, functions) reach its localhost database engines once
 * its agent supports it (feature db.containers): re-applies the users with the Docker ranges (users created
 * afterwards get them anyway) and opens the engine ports on the Docker bridges. Idempotent per engine.
 */
final class EnableContainerAccess
{
    public const FEATURE = 'db.containers';

    public function __construct(
        private readonly AgentDirectory $agents,
        private readonly ApplyDatabaseUser $apply,
        private readonly Firewalls $firewalls,
    ) {}

    /**
     * @param  ?list<string>  $features  the agent's features when known (an AgentVersionChanged event), else looked up
     */
    public function __invoke(string $serverId, ?array $features = null): bool
    {
        $serverId = strtolower($serverId);
        $supported = $features !== null
            ? in_array(self::FEATURE, $features, true)
            : ($this->agents->forServer($serverId)?->supports(self::FEATURE) ?? false);

        if (! $supported) {
            return false;
        }

        $engines = DatabaseServer::query()
            ->where('server_id', $serverId)
            ->where('dedicated', false)
            ->where('container_access', false)
            ->get();

        if ($engines->isEmpty()) {
            return false;
        }

        foreach ($engines as $engine) {
            DatabaseUser::query()
                ->where('database_server_id', $engine->id)
                ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
                ->orderBy('created_at')
                ->each(fn (DatabaseUser $user) => ($this->apply)($user, background: true));

            $engine->forceFill(['container_access' => true])->save();
        }

        $this->firewalls->converge($serverId);

        return true;
    }
}
