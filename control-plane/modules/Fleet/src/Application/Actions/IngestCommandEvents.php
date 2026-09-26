<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Application\CommandLifecycle;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Events\CommandOutputReceived;

/**
 * Stores a batch of agent events for one command, idempotently on (command_id, seq), and applies
 * the state transitions of events seen for the first time (in seq order).
 */
final class IngestCommandEvents
{
    public function __construct(private readonly CommandLifecycle $lifecycle) {}

    /**
     * @param  list<array<string, mixed>>  $events  validated event.schema.json documents
     * @return int number of new events
     */
    public function __invoke(Command $command, array $events): int
    {
        usort($events, fn (array $a, array $b) => $a['seq'] <=> $b['seq']);

        $fresh = [];

        foreach ($events as $event) {
            $inserted = DB::table('fleet_command_events')->insertOrIgnore([
                'command_id' => $command->id,
                'seq' => (int) $event['seq'],
                'kind' => $event['kind'],
                'stream' => $event['stream'] ?? null,
                'data' => $event['data'] ?? null,
                'progress' => $event['progress'] ?? null,
                'exit_code' => $event['exit_code'] ?? null,
                'result' => isset($event['result']) ? json_encode($event['result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
                'error' => $event['error'] ?? null,
                'at' => Carbon::parse((string) $event['at']),
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                $fresh[] = $event;
            }
        }

        foreach ($fresh as $event) {
            $at = Carbon::parse((string) $event['at']);

            match ($event['kind']) {
                'started' => $this->lifecycle->markStarted($command, $at),
                'finished' => $this->finish($command, $event, $at),
                default => $command->status->isTerminal() ? null : $this->lifecycle->markStarted($command, $at),
            };
        }

        $live = array_values(array_filter($fresh, fn (array $event) => in_array($event['kind'], ['started', 'output', 'progress'], true)));

        if ($live !== []) {
            CommandOutputReceived::dispatch($command->id, $command->server_id, $command->status->value, array_map(fn (array $event) => [
                'seq' => $event['seq'],
                'kind' => $event['kind'],
                'stream' => $event['stream'] ?? null,
                'data' => $event['data'] ?? null,
                'progress' => $event['progress'] ?? null,
                'at' => $event['at'],
            ], $live));
        }

        return count($fresh);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function finish(Command $command, array $event, Carbon $at): void
    {
        $result = isset($event['result']) && is_array($event['result']) ? $event['result'] : null;

        $this->lifecycle->finish(
            $command,
            isset($event['exit_code']) ? (int) $event['exit_code'] : null,
            $result,
            isset($event['error']) ? (string) $event['error'] : null,
            $at,
        );
    }
}
