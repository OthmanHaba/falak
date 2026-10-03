import { Button } from '@/components/kiln/button';
import { Callout } from '@/components/kiln/callout';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Section } from '@/components/kiln/section';
import { StatusDot } from '@/components/kiln/status';
import { Tag, type TagProps } from '@/components/kiln/tag';
import { toast } from '@/components/kiln/toast';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Play, RefreshCw, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { type MachineCheck, type MachineCheckComponent, type MachineCheckDecision, type ServerDetails } from '../types';

const DECISION_TONE: Record<MachineCheckDecision, TagProps['tone']> = {
    install: 'neutral',
    adopt: 'success',
    complete: 'info',
    block: 'danger',
    skip: 'faint',
};

function found(component: MachineCheckComponent): string | null {
    if (component.found.length === 0) return null;

    return component.found.map((item) => [item.name, item.version].filter(Boolean).join(' ') + (item.source ? ` · ${item.source}` : '')).join(', ');
}

function ComponentRow({ component }: { component: MachineCheckComponent }) {
    const what = found(component);
    const blocked = component.decision === 'block';
    // The reason repeats the first block; the other notes follow it.
    const notes = component.notes.filter((note) => !(blocked && note.message === component.reason));

    return (
        <li className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1 px-3 py-2.5" data-testid={`machine-check-${component.component}`}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-fg flex min-w-32 items-center gap-1.5 text-sm font-medium">
                    {component.severity === 'warning' && <TriangleAlert className="text-warning size-3.5" aria-label="Warning" />}
                    {component.label}
                </span>
                {what && <span className="text-fg-muted min-w-0 flex-1 truncate font-mono text-xs">{what}</span>}
                <Tag tone={DECISION_TONE[component.decision]} className="ml-auto">
                    {component.decision_label}
                </Tag>
            </div>
            <p className={cn('text-sm', blocked ? 'text-danger' : 'text-fg-muted')}>{component.reason}</p>
            {blocked && component.hint && (
                <p className="text-fg-muted text-xs">
                    <span className="text-fg font-medium">Fix: </span>
                    {component.hint}
                </p>
            )}
            {notes.length > 0 && (
                <ul className="grid gap-1">
                    {notes.map((note, index) => (
                        <li key={index} className="text-fg-muted flex items-start gap-2 text-xs">
                            <StatusDot
                                status={note.severity === 'block' ? 'failed' : note.severity === 'warning' ? 'degraded' : 'inactive'}
                                size="sm"
                                className="mt-1"
                            />
                            <span>
                                {note.message}
                                {note.hint && !(blocked && note.hint === component.hint) && <span className="text-fg-faint"> {note.hint}</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

interface Props {
    server: Pick<ServerDetails, 'id' | 'status'>;
    check: MachineCheck;
    canUpdate: boolean;
    /** Expanded and above the fold (needs attention, provisioning failed); otherwise collapsed. */
    prominent: boolean;
    reloadOnly: string[];
}

/** What the machine check found per component and what provisioning does with it (docs/plans/MACHINE_CHECK.md). */
export function MachineCheckPanel({ server, check, canUpdate, prominent, reloadOnly }: Props) {
    const [open, setOpen] = useState(prominent);
    const [busy, setBusy] = useState<'recheck' | 'provision' | null>(null);
    const running = check.status === 'running';
    const expanded = prominent || open;
    const canProvision = canUpdate && ['needs_attention', 'error'].includes(server.status) && check.status === 'finished';

    const post = (action: 'recheck' | 'provision') =>
        router.post(
            action === 'recheck' ? route('servers.inspection.store', server.id) : route('servers.provision', server.id),
            {},
            {
                preserveScroll: true,
                only: [...reloadOnly, 'flash', 'errors'],
                onStart: () => setBusy(action),
                onFinish: () => setBusy(null),
                onSuccess: () => action === 'recheck' && setOpen(true),
                onError: (errors) => toast.error(action === 'recheck' ? 'Could not re-check' : 'Could not provision', Object.values(errors)[0]),
            },
        );

    const counts = {
        block: check.components.filter((c) => c.decision === 'block').length,
        warning: check.components.filter((c) => c.severity === 'warning').length,
        adopt: check.components.filter((c) => c.decision === 'adopt' || c.decision === 'complete').length,
    };
    const summary = [
        counts.block > 0 && `${counts.block} blocked`,
        counts.warning > 0 && `${counts.warning} warning${counts.warning === 1 ? '' : 's'}`,
        counts.adopt > 0 && `${counts.adopt} already on the machine`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <Section
            id="machine-check"
            title="Machine check"
            description={
                check.checked_at ? (
                    <>
                        What Kiln found on the machine and what provisioning does with it · checked <RelativeTime value={check.checked_at} />
                    </>
                ) : (
                    'What Kiln found on the machine and what provisioning does with it.'
                )
            }
            bare
            aside={
                <>
                    {!prominent && check.components.length > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setOpen((value) => !value)}
                            aria-expanded={expanded}
                            aria-controls="machine-check-rows"
                        >
                            {expanded ? 'Hide' : summary ? `Show · ${summary}` : 'Show'}
                        </Button>
                    )}
                    {canUpdate && check.supported && (
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={<RefreshCw />}
                            loading={busy === 'recheck'}
                            disabled={running || busy !== null}
                            onClick={() => post('recheck')}
                        >
                            Re-check
                        </Button>
                    )}
                    {canProvision && (
                        <Button
                            variant="primary"
                            size="sm"
                            icon={<Play />}
                            loading={busy === 'provision'}
                            disabled={check.blocking || running || busy !== null}
                            title={check.blocking ? 'Fix the blocked components and re-check first' : undefined}
                            onClick={() => post('provision')}
                        >
                            Provision
                        </Button>
                    )}
                </>
            }
        >
            <div className="grid gap-3">
                {running && (
                    <p className="text-fg-muted flex items-center gap-2 text-sm" aria-live="polite">
                        <span className="animate-pulse-dot bg-info text-info size-1.5 rounded-full" aria-hidden />
                        Checking the machine… {check.components.length > 0 && 'The previous result is shown until it finishes.'}
                    </p>
                )}
                {check.status === 'failed' && check.error && (
                    <Callout tone="danger" title="The machine check failed">
                        {check.error}
                    </Callout>
                )}
                {check.blocking && expanded && (
                    <Callout tone="danger" title={server.status === 'active' ? 'Re-provisioning applied nothing' : 'Nothing was installed'}>
                        Fix the blocked {counts.block === 1 ? 'component' : 'components'} below on the machine, then re-check. Kiln won't change
                        software it didn't install.{server.status === 'active' && ' The server keeps running as it is.'}
                    </Callout>
                )}
                {!check.blocking && server.status === 'needs_attention' && check.status === 'finished' && (
                    <Callout tone="success" title="Nothing blocks provisioning any more">
                        Provision to install and configure the rest.
                    </Callout>
                )}
                {expanded && check.components.length > 0 && (
                    <ul id="machine-check-rows" className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-lg border">
                        {check.components.map((component) => (
                            <ComponentRow key={component.component} component={component} />
                        ))}
                    </ul>
                )}
                {!running && check.components.length === 0 && check.status !== 'failed' && (
                    <p className="text-fg-muted text-sm">No machine check yet. Re-check to see what is on the machine.</p>
                )}
            </div>
        </Section>
    );
}
