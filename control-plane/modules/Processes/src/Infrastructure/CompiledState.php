<?php

namespace Falak\Processes\Infrastructure;

/**
 * Desired proc.apply + cron.apply state of one server, with display metadata keyed by name.
 */
final readonly class CompiledState
{
    /**
     * @param  list<array<string, mixed>>  $programs  proc.apply `programs`
     * @param  list<array<string, mixed>>  $jobs  cron.apply `jobs`
     * @param  array<string, array{site_id: string, kind: string, label: string, numprocs: int, hash?: string}>  $programMeta
     * @param  array<string, array{site_id: string, kind: string, label: string, schedule: string, timezone: string, heartbeat: bool}>  $jobMeta
     * @param  list<array<string, int|string>>  $slices  proc.apply `slices`
     */
    public function __construct(
        public array $programs,
        public array $jobs,
        public array $programMeta,
        public array $jobMeta,
        public array $slices = [],
    ) {}

    /**
     * @return array{programs: list<array<string, mixed>>, slices?: list<array<string, int|string>>}
     */
    public function procPayload(): array
    {
        return ['programs' => $this->programs, ...($this->slices !== [] ? ['slices' => $this->slices] : [])];
    }

    /**
     * @return array{jobs: list<array<string, mixed>>}
     */
    public function cronPayload(): array
    {
        return ['jobs' => $this->jobs];
    }
}
