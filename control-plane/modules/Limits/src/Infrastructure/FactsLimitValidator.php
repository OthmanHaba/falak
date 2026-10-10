<?php

namespace Falak\Limits\Infrastructure;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bounds limits by the servers' facts (memory_bytes, cpus). A server that has not reported facts yet bounds nothing.
 */
final class FactsLimitValidator implements LimitValidator
{
    public function __construct(
        private readonly AgentDirectory $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function validate(?array $input, array $serverIds, string $field = 'limits', ?ResourceLimits $base = null): ResourceLimits
    {
        if ($input === null && $base === null) {
            return new ResourceLimits;
        }

        $data = Validator::make([$field => $input ?? []], ResourceLimits::rules($field))->validate();
        // What will be enforced: the input over $base (a new service's defaults). Every check below is on it, so an
        // input that only conflicts with a default (a reservation above the default limit) is refused here, not by
        // the agent later.
        $limits = ResourceLimits::fromArray((array) ($data[$field] ?? []))->withDefaults($base ?? new ResourceLimits);
        $errors = [];

        if ($limits->memoryLimit !== null && $limits->memoryReservation !== null && $limits->memoryReservation > $limits->memoryLimit) {
            $errors["{$field}.memory_reservation"] = 'The memory reservation must not exceed the memory limit.';
        }

        if ($limits->maxRestarts !== null && $limits->restartPolicy !== 'on-failure') {
            $errors["{$field}.max_restarts"] = 'Max restarts only applies with the on-failure restart policy.';
        }

        if ($limits->logMaxFiles !== null && $limits->logMaxSize === null) {
            $errors["{$field}.log_max_size"] = 'Set a log size to keep several log files.';
        }

        foreach ($this->agents->forServers(array_values(array_unique($serverIds))) as $serverId => $agent) {
            $facts = $agent->facts;
            $name = $this->servers->find((string) $serverId)?->name ?? 'the server';
            $memoryMb = is_numeric($facts['memory_bytes'] ?? null) ? intdiv((int) $facts['memory_bytes'], 1024 ** 2) : null;
            $cores = is_numeric($facts['cpus'] ?? null) ? (int) $facts['cpus'] : null;

            foreach (['memory_limit' => $limits->memoryLimit, 'memory_reservation' => $limits->memoryReservation] as $key => $mb) {
                if ($mb !== null && $memoryMb !== null && $memoryMb > 0 && $mb > $memoryMb) {
                    $errors["{$field}.{$key}"] ??= "{$name} has ".ResourceLimits::memoryLabel($memoryMb).' of memory.';
                }
            }

            if ($limits->cpus !== null && $cores !== null && $cores > 0 && $limits->cpus > $cores) {
                $errors["{$field}.cpus"] ??= "{$name} has {$cores} ".($cores === 1 ? 'CPU' : 'CPUs').'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $limits;
    }
}
