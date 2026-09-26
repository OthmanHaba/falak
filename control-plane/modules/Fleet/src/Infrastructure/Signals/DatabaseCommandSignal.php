<?php

namespace Kiln\Fleet\Infrastructure\Signals;

/**
 * Portable wake-up: re-checks the database at a fixed interval. notify() is a no-op because
 * the next check sees the queued row. Works with any database (incl. sqlite).
 */
final class DatabaseCommandSignal implements CommandSignal
{
    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param  (callable(int): void)|null  $sleeper  receives microseconds (injectable for tests)
     */
    public function __construct(private readonly int $intervalMs = 500, ?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? fn (int $microseconds) => usleep($microseconds);
    }

    public function notify(string $agentId): void {}

    public function wait(string $agentId, int $seconds, callable $check): array
    {
        $deadline = microtime(true) + $seconds;

        do {
            $found = $check();

            if ($found !== [] || connection_aborted()) {
                return $found;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return [];
            }

            ($this->sleeper)((int) (min($this->intervalMs / 1000, $remaining) * 1_000_000));
        } while (true);
    }
}
