<?php

namespace Falak\Recipes\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Recipes\Application\RunProgress;
use Falak\Recipes\Domain\BuiltinRecipe;
use Falak\Recipes\Domain\Enums\RunStatus;
use Falak\Recipes\Domain\Enums\TargetStatus;
use Falak\Recipes\Domain\Models\Recipe;
use Falak\Recipes\Domain\Models\Run;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fans a recipe out to N servers: one system.exec command per server, all queued at once so the
 * agents execute in parallel. Outcomes arrive through Fleet's CommandFinished / CommandFailed events.
 */
final class RunRecipe
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly RunProgress $progress,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  list<string>  $serverIds
     * @param  array<string, string>  $env
     */
    public function __invoke(string $organizationId, Recipe|BuiltinRecipe $recipe, array $serverIds, array $env = [], ?int $timeout = null, ?string $actorId = null): Run
    {
        $targets = $this->resolveServers($organizationId, $serverIds);
        $timeout = max(1, min((int) config('recipes.max_timeout', 3600), $timeout ?? (int) config('recipes.timeout', 900)));

        /** @var Run $run */
        $run = DB::transaction(function () use ($organizationId, $recipe, $targets, $env, $timeout, $actorId) {
            $run = Run::query()->create([
                'organization_id' => $organizationId,
                'recipe_id' => $recipe instanceof Recipe ? $recipe->id : null,
                'builtin' => $recipe instanceof BuiltinRecipe ? $recipe->key : null,
                'recipe_name' => $recipe->name,
                'script' => $recipe->script,
                'user' => $recipe->user,
                'env' => $env,
                'timeout_s' => $timeout,
                'requested_by' => $actorId,
                'status' => RunStatus::Pending,
            ]);

            foreach ($targets as $server) {
                $run->targets()->create([
                    'organization_id' => $organizationId,
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'status' => TargetStatus::Queued,
                ]);
            }

            return $run;
        });

        $payload = array_filter([
            'script' => $run->script,
            'shell' => '/bin/bash',
            'user' => $run->user,
            'env' => $env === [] ? null : $env,
        ], fn ($value) => $value !== null);

        foreach ($run->targets()->get() as $target) {
            try {
                $handle = $this->agents->dispatch($target->server_id, 'system.exec', $payload, $timeout, "recipes.run:{$target->id}");
                $target->forceFill(['command_id' => $handle->id])->save();
            } catch (AgentUnavailable) {
                $target->forceFill([
                    'status' => TargetStatus::Unavailable,
                    'error' => 'The server agent is not connected.',
                    'finished_at' => now(),
                ])->save();
            }
        }

        $this->audit->record('recipe.run', 'recipe_run', $run->id, [
            'recipe_id' => $run->recipe_id,
            'builtin' => $run->builtin,
            'name' => $run->recipe_name,
            'user' => $run->user,
            'script_sha256' => hash('sha256', $run->script),
            'env_keys' => array_keys($env),
            'server_ids' => array_map(fn (ServerData $s) => $s->id, $targets),
        ], $organizationId);

        $this->progress->refresh($run);

        return $run->refresh();
    }

    /**
     * @param  list<string>  $serverIds
     * @return list<ServerData>
     */
    private function resolveServers(string $organizationId, array $serverIds): array
    {
        $serverIds = array_values(array_unique($serverIds));

        if ($serverIds === []) {
            throw ValidationException::withMessages(['server_ids' => 'Select at least one server.']);
        }

        if (count($serverIds) > (int) config('recipes.max_servers', 100)) {
            throw ValidationException::withMessages(['server_ids' => 'Too many servers selected.']);
        }

        $available = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            $available[$server->id] = $server;
        }

        $resolved = [];

        foreach ($serverIds as $id) {
            $server = $available[$id] ?? null;

            if ($server === null) {
                throw ValidationException::withMessages(['server_ids' => 'One or more selected servers do not exist.']);
            }

            if ($server->status === ServerStatus::Deleting) {
                throw ValidationException::withMessages(['server_ids' => "Server {$server->name} is being deleted."]);
            }

            $resolved[] = $server;
        }

        return $resolved;
    }
}
