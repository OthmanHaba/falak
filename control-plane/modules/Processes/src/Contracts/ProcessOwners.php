<?php

namespace Falak\Processes\Contracts;

use Falak\Processes\Contracts\Data\ProcessOwner;

/**
 * Who a supervised program or a worker's / daemon's slice belongs to (OOM kills and restarts reported by agents).
 */
interface ProcessOwners
{
    /** A program the server runs (proc.apply name), from the last state applied to it. */
    public function program(string $serverId, string $program): ?ProcessOwner;

    /** A worker or a daemon by id (its slice: worker_<id>, daemon_<id>). */
    public function process(string $kind, string $id): ?ProcessOwner;
}
