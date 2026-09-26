<?php

namespace Kiln\Terminal\Application\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Events\CommandOutputReceived;
use Kiln\Terminal\Application\SessionTransitions;
use Kiln\Terminal\Domain\Models\TerminalFrame;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Kiln\Terminal\Events\TerminalOutput;

/**
 * Records terminal.open output as asciicast frames and relays it to live viewers.
 *
 * Deliberately SYNCHRONOUS (not ShouldQueue): every keystroke's echo travels agent → Fleet ingest →
 * this listener → Reverb, so a queue hop would add worker latency to each character typed, and
 * parallel workers could reorder chunks. The work is bounded: one indexed lookup (a no-op for
 * non-terminal commands), one insert per output event and one broadcast per ≤ chunk-size part.
 */
final class StreamTerminalOutput
{
    public function __construct(private readonly SessionTransitions $transitions) {}

    public function handle(CommandOutputReceived $event): void
    {
        if ($event->events === []) {
            return; // status-only announcement; outcomes are handled by HandleTerminalCommandOutcome
        }

        $session = TerminalSession::query()->where('command_id', $event->commandId)->first();

        if (! $session) {
            return;
        }

        $first = $event->events[0];
        $this->transitions->markOpen($session, isset($first['at']) ? Carbon::parse((string) $first['at']) : now());

        $bytes = 0;

        foreach ($event->events as $item) {
            if (($item['kind'] ?? null) !== 'output' || ! is_string($item['data'] ?? null) || $item['data'] === '') {
                continue;
            }

            $raw = base64_decode($item['data'], true);

            if ($raw === false) {
                continue; // not a PTY frame
            }

            $seq = (int) $item['seq'];
            $at = isset($item['at']) ? Carbon::parse((string) $item['at']) : now();

            $inserted = DB::table('terminal_frames')->insertOrIgnore([
                'session_id' => $session->id,
                'fleet_seq' => $seq,
                'kind' => TerminalFrame::OUTPUT,
                'offset_ms' => $session->offsetFor($at),
                'data' => $item['data'],
                'created_at' => now(),
            ]);

            if ($inserted !== 1) {
                continue; // duplicate delivery
            }

            $bytes += strlen($raw);
            $this->broadcast($session->id, $seq, $raw);
        }

        if ($bytes > 0) {
            $updates = ['recording_bytes' => DB::raw('recording_bytes + '.$bytes)];

            if ($session->last_activity_at === null || $session->last_activity_at->lt(now()->subSeconds(15))) {
                $updates['last_activity_at'] = now();
            }

            TerminalSession::query()->whereKey($session->id)->update($updates);
        }
    }

    private function broadcast(string $sessionId, int $seq, string $raw): void
    {
        // Split on raw-byte boundaries that are multiples of 3 so each part is independently valid base64.
        $maxChars = max(4, (int) config('terminal.broadcast_chunk_bytes', 6000));
        $rawPerPart = intdiv($maxChars, 4) * 3;
        $parts = str_split($raw, $rawPerPart);
        $count = count($parts);

        foreach ($parts as $index => $part) {
            TerminalOutput::dispatch($sessionId, $seq, $index, $count, base64_encode($part));
        }
    }
}
