import { AppShell, Button, Callout, ConfirmDestructive, PageHeader, Section, Select, StatusBadge, Tag, toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, LifeBuoy, RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { lossLabel, type ItemState, type ServerRecovery as Recovery, type RecoveryPlan } from '../types';

interface Props {
    server: { id: string; name: string; ipv4: string | null; status: string };
    candidates: { id: string; name: string; status: string; status_label: string; ipv4: string | null; docker: boolean }[];
    recovery: Recovery | null;
}

const ITEM_STATUS: Record<ItemState, string> = {
    pending: 'pending',
    running: 'running',
    succeeded: 'succeeded',
    skipped: 'skipped',
    manual: 'needs-attention',
    failed: 'failed',
};

const ITEM_LABEL: Partial<Record<ItemState, string>> = { manual: 'Your turn' };

/** "This server is gone": pick a replacement, read the dry run, confirm, follow the steps (retry a failed one). */
export default function ServerRecovery({ server, candidates, recovery: initial }: Props) {
    const [target, setTarget] = useState<string | undefined>(initial?.status === 'running' ? initial.target_server.id : undefined);
    const [plan, setPlan] = useState<RecoveryPlan | null>(null);
    const [planning, setPlanning] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const live = useJson<Recovery>(initial ? `/recoveries/${initial.id}` : null, { interval: initial?.status === 'running' ? 5000 : false });
    const recovery = live.data ?? initial;
    const showWizard = !recovery || recovery.status === 'succeeded';

    useEffect(() => {
        if (!target) return;
        setPlanning(true);
        requestJson<{ data: RecoveryPlan }>(`/servers/${server.id}/recovery/plan`, 'POST', { target_server_id: target })
            .then((response) => setPlan(response.data))
            .catch((e) => toast.error('Could not plan the recovery', errorMessage(e)))
            .finally(() => setPlanning(false));
    }, [server.id, target]);

    const retry = async (step: string) => {
        if (!recovery) return;
        try {
            const response = await requestJson<{ data: Recovery }>(`/recoveries/${recovery.id}/steps/${step}/retry`, 'POST');
            live.setData(response.data);
            router.reload({ only: ['recovery'] });
        } catch (e) {
            toast.error('Could not retry', errorMessage(e));
        }
    };

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Servers', href: '/servers' },
                { title: server.name, href: `/servers/${server.id}` },
                { title: 'Recovery', href: '#' },
            ]}
        >
            <Head title={`Recover ${server.name}`} />
            <div className="grid gap-6">
                <PageHeader
                    title={`This server is gone: ${server.name}`}
                    description="Its databases (from their latest backups), volumes, services and domains come back on another server. Nothing is sent to the lost server."
                    actions={
                        <Button asChild variant="ghost" icon={<ArrowLeft />}>
                            <Link href={`/servers/${server.id}`}>Back to the server</Link>
                        </Button>
                    }
                />

                {recovery && <Progress recovery={recovery} onRetry={retry} />}

                {showWizard && (
                    <>
                        <Section
                            title="1. Replacement server"
                            description={
                                <>
                                    An active server of this organization takes over. To use a new machine,{' '}
                                    <Link href="/servers/create" className="underline">
                                        create a server
                                    </Link>{' '}
                                    (through a provider or with the install command) and come back once it is active; a server that needs attention
                                    can be reprovisioned from its page.
                                </>
                            }
                        >
                            <Select
                                aria-label="Replacement server"
                                className="w-80 max-w-full"
                                value={target}
                                onValueChange={setTarget}
                                placeholder="Choose a server…"
                                options={candidates.map((candidate) => ({
                                    value: candidate.id,
                                    label: `${candidate.name}${candidate.ipv4 ? ` (${candidate.ipv4})` : ''}${candidate.status !== 'active' ? ` · ${candidate.status_label}` : ''}`,
                                }))}
                            />
                        </Section>

                        {planning && <p className="text-fg-muted text-sm">Planning…</p>}
                        {plan && !planning && <PlanView plan={plan} />}

                        {plan && !planning && (
                            <div className="flex justify-end">
                                <Button variant="danger" icon={<LifeBuoy />} onClick={() => setConfirming(true)}>
                                    Recover onto {plan.target?.name}
                                </Button>
                            </div>
                        )}

                        <ConfirmDestructive
                            open={confirming}
                            onOpenChange={setConfirming}
                            title={`Recover ${server.name} onto ${plan?.target?.name ?? ''}`}
                            description="Its services move to the replacement and its databases are recreated there from their latest backups: writes since then are lost. Type the lost server's name to confirm it is gone."
                            confirmText={server.name}
                            confirmLabel="Start the recovery"
                            processing={starting}
                            error={error}
                            onConfirm={(confirm) => {
                                setStarting(true);
                                router.post(
                                    `/servers/${server.id}/recovery`,
                                    { target_server_id: target, confirm },
                                    {
                                        onError: (errors) => setError(Object.values(errors)[0]),
                                        onSuccess: () => setConfirming(false),
                                        onFinish: () => setStarting(false),
                                    },
                                );
                            }}
                        />
                    </>
                )}
            </div>
        </AppShell>
    );
}

function PlanView({ plan }: { plan: RecoveryPlan }) {
    return (
        <>
            {plan.problems.map((problem) => (
                <Callout key={problem} tone="warning">
                    {problem}
                </Callout>
            ))}

            <Section
                title="2. Databases"
                description="Each container is recreated on the replacement with the same name and DNS name, then restored from its latest encrypted backup."
            >
                {plan.databases.length === 0 ? (
                    <p className="text-fg-muted text-sm">No database containers on this server.</p>
                ) : (
                    <ul className="divide-border grid divide-y text-sm">
                        {plan.databases.flatMap((instance) =>
                            instance.databases.map((db) => (
                                <li key={db.id} className="flex flex-wrap items-center gap-2 py-2">
                                    <span className="text-fg font-medium">
                                        {instance.name} / {db.name}
                                    </span>
                                    <Tag>{instance.engine}</Tag>
                                    {instance.pitr_enabled && <Tag>PITR on</Tag>}
                                    <span className="text-fg-muted ml-auto">
                                        {db.method === 'backup' && (
                                            <>
                                                Data loss: {lossLabel(db.data_loss_seconds)} (backup of{' '}
                                                {db.backup_at ? new Date(db.backup_at).toLocaleString() : '?'})
                                            </>
                                        )}
                                        {db.method === 'manual' && <>Customer-held key: restore it yourself from the Databases page</>}
                                        {db.method === 'none' && <span className="text-danger">No backup: it comes back empty</span>}
                                    </span>
                                </li>
                            )),
                        )}
                    </ul>
                )}
            </Section>

            <Section title="3. Volumes" description="Restored from their latest backup into a volume of the same name on the replacement.">
                {plan.volumes.length === 0 ? (
                    <p className="text-fg-muted text-sm">No volumes on this server.</p>
                ) : (
                    <ul className="divide-border grid divide-y text-sm">
                        {plan.volumes.map((volume) => (
                            <li key={volume.id} className="flex flex-wrap items-center gap-2 py-2">
                                <span className="text-fg font-medium">{volume.name}</span>
                                <Tag>{volume.kind}</Tag>
                                <span className="text-fg-muted ml-auto">
                                    {volume.method === 'backup' && <>Data loss: {lossLabel(volume.data_loss_seconds)}</>}
                                    {volume.method === 'manual' && <>Customer-held key: restore it yourself from the Volumes page</>}
                                    {volume.method === 'none' && <span className="text-danger">No backup: it starts empty</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title="4. Services" description="Their server is swapped for the replacement, then each is redeployed once the data is back.">
                {plan.sites.length === 0 ? (
                    <p className="text-fg-muted text-sm">No services ran on this server.</p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {plan.sites.map((site) => (
                            <Tag key={site.id}>{site.name}</Tag>
                        ))}
                    </div>
                )}
            </Section>

            <Section
                title="5. Domains"
                description="Domains in a Cloudflare zone Falak manages follow on their own; the others need their DNS record changed to the replacement's address."
            >
                {plan.domains.length === 0 ? (
                    <p className="text-fg-muted text-sm">No domains.</p>
                ) : (
                    <ul className="grid gap-1 text-sm">
                        {plan.domains.map((domain) => (
                            <li key={domain.name} className="flex flex-wrap items-center gap-2">
                                <span className="text-fg font-mono">{domain.name}</span>
                                <span className="text-fg-muted ml-auto">
                                    {domain.managed
                                        ? 'moved automatically'
                                        : `point it at ${[plan.target?.ipv4, plan.target?.ipv6].filter(Boolean).join(' / ') || 'the replacement'}`}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </>
    );
}

function Progress({ recovery, onRetry }: { recovery: Recovery; onRetry: (step: string) => void }) {
    return (
        <Section
            title={`Recovery onto ${recovery.target_server.name}`}
            description={
                recovery.status === 'running' ? 'Each step starts when the one before it finished. This page updates on its own.' : undefined
            }
            aside={<StatusBadge status={recovery.status} />}
        >
            <ol className="grid gap-4">
                {recovery.steps.map((step) => (
                    <li key={step.key} className="grid gap-2">
                        <div className="flex items-center gap-2">
                            <StatusBadge status={step.status} />
                            <span className="text-fg font-medium">{step.title}</span>
                            {step.status === 'failed' && (
                                <Button size="sm" className="ml-auto" icon={<RotateCw />} onClick={() => onRetry(step.key)}>
                                    Retry
                                </Button>
                            )}
                        </div>
                        {step.items.length > 0 && (
                            <ul className="border-border ml-2 grid gap-1 border-l pl-4 text-sm">
                                {step.items.map((item) => (
                                    <li key={item.id} className="flex flex-wrap items-baseline gap-2">
                                        <StatusBadge status={ITEM_STATUS[item.state]} label={ITEM_LABEL[item.state]} />
                                        <span className="text-fg">{item.label}</span>
                                        {item.message && <span className="text-fg-muted">{item.message}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ol>
        </Section>
    );
}
