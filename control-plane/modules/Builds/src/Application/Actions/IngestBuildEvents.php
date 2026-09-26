<?php

namespace Kiln\Builds\Application\Actions;

use Kiln\Builds\Application\BuildProgress;
use Kiln\Builds\Domain\Models\Build;

/**
 * Apply a batch of builder events (event.schema.json, command_id = build id) in order.
 */
final class IngestBuildEvents
{
    public function __construct(private readonly BuildProgress $progress) {}

    /**
     * @param  list<array<string, mixed>>  $events  already shape-validated
     */
    public function __invoke(Build $build, array $events): void
    {
        usort($events, fn (array $a, array $b) => $a['seq'] <=> $b['seq']);
        $output = [];

        foreach ($events as $event) {
            if ($event['kind'] === 'output') {
                $output[] = ['seq' => (int) $event['seq'], 'stream' => (string) ($event['stream'] ?? 'stdout'), 'data' => (string) ($event['data'] ?? ''), 'at' => (string) $event['at']];

                continue;
            }

            $this->flush($build, $output);

            match ($event['kind']) {
                'started' => $this->progress->started($build),
                'progress' => isset($event['progress']) ? $this->progress->progress($build, (float) $event['progress']) : null,
                'finished' => $this->finished($build, $event),
                default => null,
            };
        }

        $this->flush($build, $output);
    }

    /**
     * @param  list<array{seq: int, stream: string, data: string, at: string}>  $output
     */
    private function flush(Build $build, array &$output): void
    {
        $this->progress->output($build, $output);
        $output = [];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function finished(Build $build, array $event): void
    {
        $this->progress->started($build);
        $this->progress->finished(
            $build,
            (int) ($event['exit_code'] ?? 0),
            is_array($event['result'] ?? null) ? $event['result'] : null,
            isset($event['error']) && $event['error'] !== '' ? (string) $event['error'] : null,
        );
    }
}
