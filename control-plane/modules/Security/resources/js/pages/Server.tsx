import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { Dialog } from '@/components/falak/dialog';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Section } from '@/components/falak/section';
import { Sparkline } from '@/components/falak/sparkline';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { Link, router, usePoll } from '@inertiajs/react';
import { RefreshCw, RotateCcw, ShieldCheck, Wand2, Wrench } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ReadyBadge, ScoreRing, SeverityTag, StatusTag, type AuditSummary, type CheckStatus, type Severity } from '../components/security-ui';

interface FindingRow {
    id: string;
    title: string;
    area: string;
    status: CheckStatus;
    severity: Severity;
    evidence: string;
    fix_id: string | null;
    fix_label: string | null;
    disruptive: boolean;
}

interface FixRunRow {
    id: string;
    fix_id: string;
    label: string;
    status: 'queued' | 'applying' | 'applied' | 'unchanged' | 'failed' | 'undoing' | 'undone';
    disruptive: boolean;
    message: string | null;
    error: string | null;
    can_undo: boolean;
    applied_at: string | null;
    undone_at: string | null;
    created_at: string;
}

interface Props {
    server: ServerHeader;
    audit: (AuditSummary & { findings: FindingRow[] }) | null;
    latest: { status: 'running' | 'completed' | 'failed'; error: string | null; created_at: string } | null;
    history: { score: number; ran_at: string; production_ready: boolean }[];
    fixes: FixRunRow[];
    safeFixes: string[];
    undoDays: number;
    can: { fix: boolean; audit: boolean };
}

const AREAS: Record<string, string> = {
    ssh: 'SSH',
    updates: 'Updates',
    firewall: 'Firewall',
    intrusion: 'Intrusion prevention',
    docker: 'Docker',
    files: 'Files',
    kernel: 'Kernel',
    accounts: 'Accounts',
    time: 'Time',
    backups: 'Backups',
    audit: 'Audit',
};
const ORDER: Record<CheckStatus, number> = { fail: 0, warn: 1, info: 2, pass: 3 };
const SEVERITY: Record<Severity, number> = { critical: 0, high: 1, medium: 2, low: 3, info: 4 };
const RELOAD = ['server', 'audit', 'latest', 'history', 'fixes', 'safeFixes'];

export default function SecurityServer({ server, audit, latest, history, fixes, safeFixes, undoDays, can }: Props) {
    const [confirming, setConfirming] = useState<FindingRow | null>(null);
    const [rebootAt, setRebootAt] = useState('04:00');
    const [busy, setBusy] = useState<string | null>(null);
    const running = latest?.status === 'running' || fixes.some((f) => ['queued', 'applying', 'undoing'].includes(f.status));
    usePoll(running ? 3_000 : 60_000, { only: RELOAD });

    const groups = useMemo(() => {
        const byArea = new Map<string, FindingRow[]>();
        for (const f of audit?.findings ?? []) byArea.set(f.area, [...(byArea.get(f.area) ?? []), f]);
        const sorted = [...byArea.entries()].map(([area, rows]) => ({
            area,
            rows: rows.sort((a, b) => ORDER[a.status] - ORDER[b.status] || SEVERITY[a.severity] - SEVERITY[b.severity]),
            open: rows.filter((r) => r.status === 'fail' || r.status === 'warn').length,
        }));

        return sorted.sort((a, b) => b.open - a.open || a.area.localeCompare(b.area));
    }, [audit]);

    const inFlight = (fixId: string) => fixes.some((f) => f.fix_id === fixId && ['queued', 'applying', 'undoing'].includes(f.status));

    const post = (key: string, url: string, data: Record<string, string | boolean>, done: string) => {
        setBusy(key);
        router.post(url, data, {
            preserveScroll: true,
            only: RELOAD,
            onSuccess: () => toast.success(done),
            onError: (errors) => toast.error('Could not do that', Object.values(errors)[0]),
            onFinish: () => setBusy(null),
        });
    };

    const fix = (f: FindingRow, confirm = false) => {
        if (f.disruptive && !confirm) return setConfirming(f);
        const data: Record<string, string | boolean> = { fix_id: f.fix_id ?? '', confirm };
        if (f.fix_id === 'updates.reboot') data.reboot_at = rebootAt;
        post(`fix-${f.id}`, `/security/servers/${server.id}/fixes`, data, 'Fix started');
        setConfirming(null);
    };

    return (
        <ServerLayout
            server={server}
            tab="security"
            reloadOnly={RELOAD}
            actions={
                can.fix && (
                    <>
                        <Button
                            icon={<RefreshCw />}
                            loading={busy === 'audit' || latest?.status === 'running'}
                            disabled={server.status !== 'active'}
                            onClick={() => post('audit', `/security/servers/${server.id}/audit`, {}, 'Audit started')}
                        >
                            Run now
                        </Button>
                        <Button
                            variant="primary"
                            icon={<Wand2 />}
                            disabled={safeFixes.length === 0}
                            loading={busy === 'safe'}
                            onClick={() => post('safe', `/security/servers/${server.id}/fixes/safe`, {}, `Applying ${safeFixes.length} safe fix(es)`)}
                        >
                            Fix all safe{safeFixes.length > 0 ? ` (${safeFixes.length})` : ''}
                        </Button>
                    </>
                )
            }
        >
            {audit ? (
                <div className="border-border bg-surface-1 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg border px-4 py-4">
                    <ScoreRing score={audit.score} />
                    <div className="grid min-w-0 flex-1 gap-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <ReadyBadge ready={audit.production_ready} />
                            <span className="text-fg-muted text-xs">
                                {audit.counts.fail ?? 0} failing · {audit.counts.warn ?? 0} warnings · {audit.counts.pass ?? 0} passing
                            </span>
                        </div>
                        <p className="text-fg-muted text-xs">
                            Last run <RelativeTime value={audit.ran_at} /> ({audit.trigger})
                            {latest?.status === 'running' && ' · a new audit is running'}
                        </p>
                    </div>
                    {history.length > 1 && (
                        <div className="grid gap-1">
                            <span className="text-fg-faint text-xs">Score, last 30 days</span>
                            <Sparkline values={history.map((h) => h.score)} label="Security score per audit" type="line" width={160} height={32} />
                        </div>
                    )}
                </div>
            ) : (
                <EmptyState
                    icon={<ShieldCheck />}
                    title={latest?.status === 'running' ? 'The first audit is running' : 'Not audited yet'}
                    description="The agent checks SSH, updates, the firewall, Docker, files, kernel settings, accounts and time sync. Servers are audited daily."
                />
            )}

            {latest?.status === 'failed' && (
                <Callout tone="danger" title="The last audit failed">
                    {latest.error}
                </Callout>
            )}

            {groups.map((group) => (
                <Section
                    key={group.area}
                    title={AREAS[group.area] ?? group.area}
                    description={group.open > 0 ? `${group.open} need attention` : 'All good'}
                    aside={
                        group.area === 'backups' ? (
                            <Link href="/databases" className="text-primary text-sm">
                                Backups
                            </Link>
                        ) : group.area === 'firewall' ? (
                            <Link href={`/servers/${server.id}/firewall`} className="text-primary text-sm">
                                Firewall rules
                            </Link>
                        ) : undefined
                    }
                >
                    <ul className="divide-border divide-y">
                        {group.rows.map((f) => (
                            <li key={f.id} className="flex flex-wrap items-start gap-x-3 gap-y-2 py-3">
                                <StatusTag status={f.status} />
                                <div className="grid min-w-0 flex-1 gap-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-fg text-sm font-medium">{f.title}</span>
                                        {f.status !== 'pass' && f.status !== 'info' && <SeverityTag severity={f.severity} />}
                                    </div>
                                    <p className="text-fg-muted font-mono text-xs break-words">{f.evidence}</p>
                                </div>
                                {can.fix && f.fix_id && (f.status === 'fail' || f.status === 'warn') && (
                                    <Button
                                        size="sm"
                                        icon={<Wrench />}
                                        variant={f.disruptive ? 'secondary' : 'primary'}
                                        loading={busy === `fix-${f.id}` || inFlight(f.fix_id)}
                                        title={f.fix_label ?? undefined}
                                        onClick={() => fix(f)}
                                    >
                                        Fix{f.disruptive ? '…' : ''}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            ))}

            {fixes.length > 0 && (
                <Section title="Applied fixes" description={`Fixes with a backup can be undone for ${undoDays} days.`}>
                    <ul className="divide-border divide-y">
                        {fixes.map((run) => (
                            <li key={run.id} className="flex flex-wrap items-start gap-x-3 gap-y-2 py-3">
                                <Tag tone={run.status === 'failed' ? 'danger' : run.status === 'applied' ? 'success' : 'neutral'}>{run.status}</Tag>
                                <div className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="text-fg text-sm">{run.label}</span>
                                    <span className="text-fg-muted text-xs">
                                        <RelativeTime value={run.applied_at ?? run.created_at} />
                                        {run.message && ` · ${run.message}`}
                                    </span>
                                    {run.error && <span className="text-danger text-xs">{run.error}</span>}
                                </div>
                                {can.fix && run.can_undo && (
                                    <Button
                                        size="sm"
                                        icon={<RotateCcw />}
                                        loading={busy === `undo-${run.id}`}
                                        onClick={() =>
                                            post(`undo-${run.id}`, `/security/servers/${server.id}/fixes/${run.id}/undo`, {}, 'Undoing the fix')
                                        }
                                    >
                                        Undo
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title="Apply a disruptive fix?"
                description={confirming?.fix_label}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirming(null)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={() => confirming && fix(confirming, true)}>
                            Apply
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">
                    This fix can interrupt the server: it restarts services, reboots or changes how people log in over SSH. The agent validates the
                    change and rolls it back if it fails.
                </p>
                {confirming?.fix_id === 'updates.reboot' && (
                    <Field label="Reboot at (server time)" id="reboot_at">
                        <Input id="reboot_at" type="time" value={rebootAt} onChange={(e) => setRebootAt(e.target.value)} />
                    </Field>
                )}
            </Dialog>
        </ServerLayout>
    );
}
