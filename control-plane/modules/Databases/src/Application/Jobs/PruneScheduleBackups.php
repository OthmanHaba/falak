<?php

namespace Kiln\Databases\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kiln\Databases\Application\Actions\PruneBackups;
use Kiln\Databases\Domain\Models\BackupSchedule;

final class PruneScheduleBackups implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public string $scheduleId) {}

    public function uniqueId(): string
    {
        return $this->scheduleId;
    }

    public function handle(PruneBackups $prune): void
    {
        $schedule = BackupSchedule::query()->find($this->scheduleId);

        if ($schedule) {
            $prune($schedule);
        }
    }
}
