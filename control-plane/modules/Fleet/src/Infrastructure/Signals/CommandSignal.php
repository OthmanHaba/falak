<?php

namespace Kiln\Fleet\Infrastructure\Signals;

/**
 * Wakes long-polling agents as soon as a command is queued for them.
 */
interface CommandSignal
{
    public function notify(string $agentId): void;

    /**
     * Repeatedly call $check until it returns a non-empty list or $seconds elapse.
     *
     * @template T
     *
     * @param  callable(): list<T>  $check
     * @return list<T>
     */
    public function wait(string $agentId, int $seconds, callable $check): array;
}
