<?php

namespace Falak\Databases\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Databases\Domain\Models\DatabaseUser;

/**
 * Replaces a user's grants. Databases must live on the same database server.
 */
final class SyncGrants
{
    /**
     * @param  list<array{database_id: string, privileges?: list<string>|null}>  $grants
     */
    public function __invoke(DatabaseUser $user, array $grants): void
    {
        $server = $user->databaseServer;
        $allowed = $server->engine->privileges();
        $byDatabase = [];

        foreach ($grants as $i => $grant) {
            $privileges = array_values(array_unique(array_map('strtoupper', $grant['privileges'] ?? []))) ?: ['ALL PRIVILEGES'];

            if (array_diff($privileges, $allowed) !== []) {
                throw ValidationException::withMessages(["grants.{$i}.privileges" => 'Unsupported privilege for '.$server->engine->label().'.']);
            }

            if (in_array('ALL PRIVILEGES', $privileges, true)) {
                $privileges = ['ALL PRIVILEGES'];
            }

            $byDatabase[$grant['database_id']] = $privileges;
        }

        $valid = $server->databases()->whereIn('id', array_keys($byDatabase))->pluck('id')->all();

        if (count($valid) !== count($byDatabase)) {
            throw ValidationException::withMessages(['grants' => 'Grants may only reference databases on this server.']);
        }

        $user->grants()->whereNotIn('database_id', $valid)->delete();

        foreach ($byDatabase as $databaseId => $privileges) {
            $user->grants()->updateOrCreate(['database_id' => $databaseId], ['privileges' => $privileges]);
        }

        $user->unsetRelation('grants');
    }
}
