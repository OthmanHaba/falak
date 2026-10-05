<?php

namespace Falak\Databases\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Falak\Databases\Application\Actions\DeleteBackupSchedule;
use Falak\Databases\Application\Actions\RunBackupSchedule;
use Falak\Databases\Application\Actions\SaveBackupSchedule;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Kernel\Http\Controller;

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
            'compression' => ['nullable', 'in:gzip,none'],
            'enabled' => ['boolean'],
        ];
    }

    public function store(Request $request, DatabaseServer $databaseServer, SaveBackupSchedule $save): RedirectResponse
    {
        $this->authorize('manage', $databaseServer);

        $save($databaseServer, $request->validate($this->rules()), null, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, BackupSchedule $backupSchedule, SaveBackupSchedule $save): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $save($backupSchedule->databaseServer, $request->validate($this->rules()), $backupSchedule);

        return back();
    }

    public function run(Request $request, BackupSchedule $backupSchedule, RunBackupSchedule $run): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $run($backupSchedule, 'manual', $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(BackupSchedule $backupSchedule, DeleteBackupSchedule $delete): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $delete($backupSchedule);

        return back();
    }
}
