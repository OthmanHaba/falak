<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\DeleteBackupSchedule;
use Falak\Databases\Application\Actions\RunBackupSchedule;
use Falak\Databases\Application\Actions\SaveBackupSchedule;
use Falak\Databases\Application\Actions\StartDrill;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        $data = $request->validate($this->rules());
        $this->authorizeDrillServer($request, $instance->organization_id, $data, null);
        $save($instance, $data, null, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, BackupSchedule $backupSchedule, SaveBackupSchedule $save): RedirectResponse
    {
        $this->authorize('manage', $backupSchedule);

        $data = $request->validate($this->rules());
        $this->authorizeDrillServer($request, $backupSchedule->organization_id, $data, $backupSchedule->drill_server_id);
        $save($backupSchedule->instance, $data, $backupSchedule);

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

    /**
     * Drills on another server put the restored data there: choosing (or changing) a drill server takes the restore
     * permission (admins), like restoring a backup does.
     *
     * @param  array<string, mixed>  $data
     */
    private function authorizeDrillServer(Request $request, string $organizationId, array $data, ?string $current): void
    {
        $server = isset($data['drill_server_id']) && $data['drill_server_id'] !== '' ? strtolower((string) $data['drill_server_id']) : null;

        if ($server !== null && $server !== $current && ! app(OrganizationAccess::class)->can($request->user(), $organizationId, DatabasesPolicy::RESTORE)) {
            throw ValidationException::withMessages(['drill_server_id' => 'Only admins can run drills on another server (it receives the restored data).']);
        }
    }
}
