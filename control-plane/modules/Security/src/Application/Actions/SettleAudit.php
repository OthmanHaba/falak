<?php

namespace Falak\Security\Application\Actions;

use Falak\Databases\Contracts\BackupPosture;
use Falak\Network\Contracts\Firewalls;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\Finding;
use Falak\Security\Domain\Score;
use Falak\Security\Events\CriticalFindingDetected;
use Falak\Security\Events\SecurityScoreDropped;
use Falak\Security\Events\UnexpectedPortDetected;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Stores an audit's findings (the agent's checks, the firewall's deny rules applied to them, and the backup checks
 * computed here), scores it and raises the alerts: the score dropped by 10 points or more, a high or critical check
 * started failing, a port started listening publicly.
 */
final class SettleAudit
{
    /** Alert when the score falls by at least this many points between two audits. */
    public const SCORE_DROP = 10;

    private const AREAS = ['ssh', 'updates', 'firewall', 'intrusion', 'docker', 'files', 'kernel', 'accounts', 'time', 'audit', 'backups'];

    public function __construct(
        private readonly BackupPosture $backups,
        private readonly Firewalls $firewalls,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __invoke(Audit $audit, ?array $result): void
    {
        $checks = [
            ...$this->closedByFirewall($audit->server_id, $this->agentChecks($result['checks'] ?? [])),
            ...$this->backupChecks($audit->server_id),
        ];
        $score = Score::of($checks);

        $previous = Audit::query()
            ->where('server_id', $audit->server_id)
            ->where('status', AuditStatus::Completed)
            ->whereKeyNot($audit->id)
            ->latest('ran_at')
            ->latest('id')
            ->first();
        $before = $previous?->findings()->get()->keyBy('check_id') ?? collect();

        DB::transaction(function () use ($audit, $checks, $score, $result) {
            foreach ($checks as $check) {
                Finding::query()->create([...$check, 'audit_id' => $audit->id, 'organization_id' => $audit->organization_id, 'server_id' => $audit->server_id]);
            }

            $audit->forceFill([
                'status' => AuditStatus::Completed,
                'score' => $score['score'],
                'counts' => $score['counts'],
                'production_ready' => $score['production_ready'],
                'duration_ms' => is_int($result['duration_ms'] ?? null) ? $result['duration_ms'] : null,
                'ran_at' => now(),
                'error' => null,
            ])->save();
        });

        $name = $this->servers->find($audit->server_id)?->name ?? $audit->server_id;

        if ($previous !== null && $previous->score !== null && $previous->score - $score['score'] >= self::SCORE_DROP) {
            SecurityScoreDropped::dispatch($audit->server_id, $audit->organization_id, $name, $previous->score, $score['score']);
        }

        foreach ($checks as $check) {
            $was = $before->get($check['check_id']);
            $port = str_starts_with($check['check_id'], 'firewall.port.') || $check['check_id'] === 'firewall.docker_published';

            if ($port && in_array($check['status'], ['fail', 'warn'], true) && ! ($was instanceof Finding && $was->failing())) {
                UnexpectedPortDetected::dispatch($audit->server_id, $audit->organization_id, $name, $check['check_id'], $check['title'], $check['evidence']);
            } elseif ($check['status'] === 'fail' && in_array($check['severity'], ['critical', 'high'], true) && ! ($was instanceof Finding && $was->status === 'fail')) {
                CriticalFindingDetected::dispatch($audit->server_id, $audit->organization_id, $name, $check['check_id'], $check['title'], $check['severity'], $check['evidence']);
            }
        }
    }

    /**
     * The agent's checks, shape-checked (anything malformed is dropped; ids are unique).
     *
     * @return list<array{check_id: string, title: string, area: string, status: string, severity: string, evidence: string, fix_id: ?string, disruptive: bool}>
     */
    private function agentChecks(mixed $raw): array
    {
        $checks = [];

        foreach (is_array($raw) ? $raw : [] as $check) {
            if (! is_array($check) || ! is_string($check['id'] ?? null) || ! preg_match('/^[a-z0-9_.-]{1,120}$/', $check['id'])
                || ! in_array($check['status'] ?? null, Score::STATUSES, true) || ! in_array($check['severity'] ?? null, Score::SEVERITIES, true)
                || ! in_array($check['area'] ?? null, self::AREAS, true) || isset($checks[$check['id']])) {
                continue;
            }

            $checks[$check['id']] = [
                'check_id' => $check['id'],
                'title' => mb_substr((string) ($check['title'] ?? $check['id']), 0, 200),
                'area' => $check['area'],
                'status' => $check['status'],
                'severity' => $check['severity'],
                'evidence' => mb_substr((string) ($check['evidence'] ?? ''), 0, 600),
                'fix_id' => is_string($check['fix_id'] ?? null) && $check['fix_id'] !== '' ? mb_substr($check['fix_id'], 0, 120) : null,
                'disruptive' => (bool) ($check['disruptive'] ?? false),
            ];
        }

        return array_values($checks);
    }

    /**
     * A public listener on a port a deny rule closes is not reachable: it passes.
     *
     * @param  list<array{check_id: string, title: string, area: string, status: string, severity: string, evidence: string, fix_id: ?string, disruptive: bool}>  $checks
     * @return list<array{check_id: string, title: string, area: string, status: string, severity: string, evidence: string, fix_id: ?string, disruptive: bool}>
     */
    private function closedByFirewall(string $serverId, array $checks): array
    {
        $denied = null;

        foreach ($checks as &$check) {
            if (! preg_match('/^firewall\.port\.(tcp|udp)\.(\d+)$/', $check['check_id'], $m)) {
                continue;
            }

            $denied ??= $this->firewalls->deniedPorts($serverId);

            if (self::covers($denied, $m[1], (int) $m[2])) {
                $check = [...$check, 'status' => 'pass', 'fix_id' => null, 'evidence' => $check['evidence'].'; closed by a firewall deny rule'];
            }
        }

        return $checks;
    }

    /**
     * @param  list<string>  $ports  "tcp/8080", "udp/8000-8100"
     */
    public static function covers(array $ports, string $protocol, int $port): bool
    {
        foreach ($ports as $entry) {
            [$proto, $range] = array_pad(explode('/', $entry, 2), 2, '');
            [$from, $to] = array_pad(explode('-', $range, 2), 2, $range);

            if ($proto === $protocol && ctype_digit($from) && ctype_digit($to) && $port >= (int) $from && $port <= (int) $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The backup checks: computed here from the Databases module, never by the agent.
     *
     * @return list<array{check_id: string, title: string, area: string, status: string, severity: string, evidence: string, fix_id: ?string, disruptive: bool}>
     */
    private function backupChecks(string $serverId): array
    {
        $databases = $this->backups->forServer($serverId);
        $check = fn (string $id, string $title, string $status, string $severity, string $evidence) => [
            'check_id' => $id, 'title' => $title, 'area' => 'backups', 'status' => $status, 'severity' => $severity, 'evidence' => $evidence, 'fix_id' => null, 'disruptive' => false,
        ];

        if ($databases === []) {
            return [$check('backups.coverage', 'Databases are backed up', 'info', 'info', 'No databases on this server.')];
        }

        $recent = now()->subDays(7);
        $drilled = now()->subDays(30);
        $missing = $unencrypted = $undrilled = [];

        foreach ($databases as $database) {
            if ($database->lastBackupAt === null || $database->lastBackupAt->lessThan($recent)) {
                $missing[] = $database->name;

                continue;
            }

            if ($database->encrypted === false) {
                $unencrypted[] = $database->name;
            }

            if ($database->lastDrillAt === null || $database->lastDrillAt->lessThan($drilled)) {
                $undrilled[] = $database->name;
            }
        }

        $names = fn (array $list) => implode(', ', array_slice($list, 0, 5)).(count($list) > 5 ? ' and '.(count($list) - 5).' more' : '');
        $n = count($databases);

        return [
            $missing === []
                ? $check('backups.coverage', 'Databases are backed up', 'pass', 'high', "{$n} database(s), each backed up within 7 days.")
                : $check('backups.coverage', 'Databases are backed up', 'fail', 'high', 'No successful backup in 7 days: '.$names($missing).'.'),
            $unencrypted === []
                ? $check('backups.encryption', 'Backups are encrypted', 'pass', 'medium', 'Every latest backup is encrypted.')
                : $check('backups.encryption', 'Backups are encrypted', 'warn', 'medium', 'Latest backup not encrypted: '.$names($unencrypted).'.'),
            $undrilled === []
                ? $check('backups.drills', 'Backups are restore-tested', 'pass', 'low', 'Every backed-up database passed a restore drill within 30 days.')
                : $check('backups.drills', 'Backups are restore-tested', 'warn', 'low', 'No passed restore drill in 30 days: '.$names($undrilled).'.'),
        ];
    }
}
