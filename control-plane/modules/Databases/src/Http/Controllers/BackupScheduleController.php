<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\DeleteBackupSchedule;
use Falak\Databases\Application\Actions\RunBackupSchedule;
use Falak\Databases\Application\Actions\SaveBackupSchedule;
use Falak\Databases\Application\Actions\StartDrill;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BackupScheduleController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'storage_provider_id' => ['required', 'string'],
            'database_ids' => ['required', 'array', 'min:1', 'max:200'],
            'database_ids.*' => ['string'],
            'cron' => ['required', 'string', 'max:120'],
            'retention_count' => ['nullable', 'integer', 'between:1,1000'],
            'retention_days' => ['nullable', 'integer', 'between:1,3650'],
            'enabled' => ['boolean'],
            'encryption_mode' => ['nullable', 'in:cp,customer'],
            'age_recipient' => ['nullable', 'string', 'max:100'],
            'drill' => ['nullable', 'in:off,weekly,monthly'],
            'drill_query' => ['nullable', 'string', 'max:4000'],
            'drill_server_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function store(Request $request, DatabaseInstance $instance, SaveBackupSchedule $save): RedirectResponse
    {
        $this->authorize('manage', $instance);

        $save($instance, $request->validate($this->rules()), null, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, BackupSchedule $backupSchedule, SaveBackupSchedule $save): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $save($backupSchedule->instance, $request->validate($this->rules()), $backupSchedule);

        return back();
    }

    public function run(Request $request, BackupSchedule $backupSchedule, RunBackupSchedule $run): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $run($backupSchedule, 'manual', $request->user()?->getAuthIdentifier());

        return back();
    }

    /** POST /databases/schedules/{schedule}/drill: a restore drill now (the schedule's next one stays as planned). */
    public function drill(Request $request, BackupSchedule $backupSchedule, StartDrill $start): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $start($backupSchedule, true, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(BackupSchedule $backupSchedule, DeleteBackupSchedule $delete): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $delete($backupSchedule);

        return back();
    }
}
