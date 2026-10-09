<?php

namespace Falak\Security\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Security\Application\FixRunner;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Enums\FixStatus;
use Falak\Security\Domain\FixCatalogue;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\Finding;
use Falak\Security\Domain\Models\FixRun;
use Falak\Servers\Contracts\Data\ServerData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies fixes the server's latest audit offers: one at a time (disruptive ones only once confirmed), or every
 * non-disruptive one in a batch that runs in sequence ("Fix all safe").
 */
final class ApplyFixes
{
    public function __construct(
        private readonly FixRunner $runner,
        private readonly AuditLog $audit,
    ) {}

    public function one(ServerData $server, string $fixId, string $userId, bool $confirmed, ?string $rebootAt = null): FixRun
    {
        $fix = FixCatalogue::find($fixId);

        if ($fix === null || ! in_array($fixId, $this->offered($server->id), true)) {
            throw ValidationException::withMessages(['fix_id' => 'The latest audit does not offer this fix; run the audit again.']);
        }

        if ($fix['disruptive'] && ! $confirmed) {
            throw ValidationException::withMessages(['confirm' => 'This fix can interrupt the server (restarts, reboots or SSH access): confirm it.']);
        }

        if ($this->inFlight($server->id, $fixId)) {
            throw ValidationException::withMessages(['fix_id' => 'This fix is already running.']);
        }

        $run = $this->create($server, $fixId, $fix['disruptive'], $fix['undoable'], $userId, FixStatus::Applying);
        $this->audit->record('security.fix_requested', 'server', $server->id, ['fix_id' => $fixId, 'run_id' => $run->id, 'disruptive' => $fix['disruptive']], $server->organizationId);

        return $this->runner->start($run, $fixId === 'updates.reboot' && $rebootAt !== null ? ['reboot_at' => $rebootAt] : []);
    }

    /**
     * Every non-disruptive fix the latest audit offers, run one after the other.
     *
     * @return list<FixRun>
     */
    public function allSafe(ServerData $server, string $userId): array
    {
        $ids = array_values(array_filter($this->offered($server->id), function (string $id) use ($server) {
            $fix = FixCatalogue::find($id);

            return $fix !== null && ! $fix['disruptive'] && ! $this->inFlight($server->id, $id);
        }));

        if ($ids === []) {
            throw ValidationException::withMessages(['fix_id' => 'No safe fixes to apply.']);
        }

        // Control-plane fixes (firewall rules) first: they settle at once.
        usort($ids, fn (string $a, string $b) => [FixCatalogue::find($a)['agent'] ?? true, $a] <=> [FixCatalogue::find($b)['agent'] ?? true, $b]);

        $batch = (string) Str::ulid();
        $runs = DB::transaction(fn () => array_map(function (string $id, int $i) use ($server, $userId, $batch) {
            $fix = FixCatalogue::find($id);

            return $this->create($server, $id, false, (bool) $fix['undoable'], $userId, FixStatus::Queued, $batch, $i);
        }, $ids, array_keys($ids)));

        $this->audit->record('security.fix_all_safe', 'server', $server->id, ['fix_ids' => $ids, 'batch_id' => $batch], $server->organizationId);

        $this->runner->start($runs[0]);

        return array_map(fn (FixRun $run) => $run->refresh(), $runs);
    }

    /**
     * Fix ids the latest completed audit offers for findings that need attention.
     *
     * @return list<string>
     */
    public function offered(string $serverId): array
    {
        $audit = Audit::query()->where('server_id', $serverId)->where('status', AuditStatus::Completed)->latest('ran_at')->latest('id')->first();

        if ($audit === null) {
            return [];
        }

        return $audit->findings()
            ->whereIn('status', ['fail', 'warn'])
            ->whereNotNull('fix_id')
            ->get()
            ->map(fn (Finding $finding) => (string) $finding->fix_id)
            ->filter(fn (string $id) => FixCatalogue::find($id) !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function inFlight(string $serverId, string $fixId): bool
    {
        return FixRun::query()->where('server_id', $serverId)->where('fix_id', $fixId)
            ->whereIn('status', [FixStatus::Queued, FixStatus::Applying, FixStatus::Undoing])->exists();
    }

    private function create(ServerData $server, string $fixId, bool $disruptive, bool $undoable, string $userId, FixStatus $status, ?string $batch = null, int $position = 0): FixRun
    {
        return FixRun::query()->create([
            'organization_id' => $server->organizationId,
            'server_id' => $server->id,
            'fix_id' => $fixId,
            'status' => $status,
            'disruptive' => $disruptive,
            'undoable' => $undoable,
            'batch_id' => $batch,
            'position' => $position,
            'applied_by' => $userId,
        ]);
    }
}
