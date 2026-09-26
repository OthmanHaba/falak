<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\Command;

/**
 * Atomically moves queued commands to "delivered" for one poll. Concurrent polls of the same
 * agent never receive the same command twice.
 */
final class ClaimCommands
{
    public const BATCH = 20;

    /**
     * @return list<Command>
     */
    public function __invoke(Agent $agent): array
    {
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
