<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\Command;

/**
 * Atomically moves queued commands to "delivered" for one poll. Concurrent polls of the same
 * agent never receive the same command twice. Only the agent's current process (session) claims: a long-poll
 * left behind by a process that has since restarted keeps running on the server until its deadline, and must not
 * take commands meant for the new process.
 */
final class ClaimCommands
{
    public const BATCH = 20;

    /**
     * @return list<Command>
     */
    public function __invoke(Agent $agent, ?string $session = null): array
    {
        if (Agent::query()->whereKey($agent->id)->value('session_id') !== $session) {
            return [];
        }

        $ids = $agent->commands()
            ->where('status', CommandStatus::Queued)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        $claimed = [];

        foreach ($ids as $id) {
            $updated = DB::table('fleet_commands')
                ->where('id', $id)
                ->where('status', CommandStatus::Queued->value)
                ->update([
                    'status' => CommandStatus::Delivered->value,
                    'delivered_at' => now(),
                    'delivered_session' => $session,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            if ($updated === 1) {
                $claimed[] = $id;
            }
        }

        if ($claimed === []) {
            return [];
        }

        return Command::query()->whereIn('id', $claimed)->orderBy('queued_at')->orderBy('id')->get()->all();
    }
}
