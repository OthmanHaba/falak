<?php

namespace Kiln\Fleet\Infrastructure\Signals;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Low-latency wake-up via Redis lists: notify() RPUSHes a token, waiters BLPOP on it.
 * A token pushed between the database check and BLPOP is not lost (it stays in the list).
 *
 * $beforeBlock runs before every BLPOP: the service provider uses it to hand the database connection back
 * while the request only waits, so N long-polling agents do not pin N Postgres connections.
 */
final class RedisCommandSignal implements CommandSignal
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection = 'default',
        private readonly ?Closure $beforeBlock = null,
    ) {}

    public function notify(string $agentId): void
    {
        $redis = $this->redis->connection($this->connection);
        $key = $this->key($agentId);

        $redis->command('rpush', [$key, '1']);
        $redis->command('expire', [$key, 120]);
    }

    public function wait(string $agentId, int $seconds, callable $check): array
    {
        $deadline = microtime(true) + $seconds;
        $redis = $this->redis->connection($this->connection);

        do {
            $found = $check();

            if ($found !== []) {
                return $found;
            }

            $remaining = (int) ceil($deadline - microtime(true));

            if ($remaining <= 0) {
                return [];
            }

            if ($this->beforeBlock !== null) {
                ($this->beforeBlock)();
            }

            $redis->command('blpop', [[$this->key($agentId)], $remaining]);
        } while (microtime(true) < $deadline);

        return $check();
    }

    private function key(string $agentId): string
    {
        return "kiln:fleet:wake:{$agentId}";
    }
}
