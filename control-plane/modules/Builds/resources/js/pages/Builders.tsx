import { Button } from '@/components/kiln/button';
import { Callout } from '@/components/kiln/callout';
import { Checkbox } from '@/components/kiln/checkbox';
import { CodeBlock } from '@/components/kiln/code-block';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { DataTable, type DataTableColumn } from '@/components/kiln/data-table';
import { EmptyState } from '@/components/kiln/empty-state';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { type MenuAction } from '@/components/kiln/menu';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Section } from '@/components/kiln/section';
import { StatusBadge, StatusDot } from '@/components/kiln/status';
import { Tag } from '@/components/kiln/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { Link, router, useForm } from '@inertiajs/react';
import { Hammer, Plus, Power, RefreshCw, Server, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

interface BuilderRow {
    id: string;
    name: string;
    kind: 'local' | 'server' | 'external';
    server_id: string | null;
    shared: boolean;
    modes: string[];
    enabled: boolean;
    online: boolean;
    reported_name: string | null;
    last_ip: string | null;
    last_seen_at: string | null;
}

interface Props {
    builders: BuilderRow[];
    localConfigured: boolean;
    panelUrl: string;
    plainToken: string | null;
    can: { manage: boolean };
}

const MODES = [
    { value: 'native', label: 'Native', hint: 'Railpack / language toolchains' },
    { value: 'docker', label: 'Docker', hint: 'Dockerfile builds with BuildKit' },
];

function builderStatus(builder: BuilderRow) {
    if (!builder.enabled) return <StatusBadge status="inactive" label="Disabled" />;

    return builder.online ? <StatusBadge status="online" /> : <StatusBadge status="offline" />;
}

export default function Builders({ builders, localConfigured, panelUrl, plainToken, can }: Props) {
    const form = useForm<{ name: string; modes: string[] }>({ name: '', modes: ['native', 'docker'] });
    const [removing, setRemoving] = useState<BuilderRow | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    const local = builders.filter((builder) => builder.shared || builder.kind === 'local');
    const servers = builders.filter((builder) => !builder.shared && builder.kind === 'server');
    const external = builders.filter((builder) => !builder.shared && builder.kind === 'external');

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('builds.builders.store'), { preserveScroll: true, onSuccess: () => form.reset('name') });
    };

    const toggleMode = (mode: string, on: boolean) =>
        form.setData('modes', on ? [...new Set([...form.data.modes, mode])] : form.data.modes.filter((item) => item !== mode));

    const act = (builder: BuilderRow, visit: (done: () => void) => void) => {
        setBusy(builder.id);
        visit(() => setBusy(null));
    };

    const setEnabled = (builder: BuilderRow, enabled: boolean) =>
        act(builder, (done) =>
            router.patch(route('builds.builders.update', builder.id), { enabled }, { preserveScroll: true, onFinish: done }),
        );

    const reinstall = (builder: BuilderRow) =>
        act(builder, (done) => router.post(route('builds.builders.reinstall', builder.id), {}, { preserveScroll: true, onFinish: done }));

    const remove = () =>
        new Promise<void>((resolve) => {
            if (!removing) return resolve();
            router.delete(route('builds.builders.destroy', removing.id), {
                preserveScroll: true,
                onSuccess: () => setRemoving(null),
                onFinish: () => resolve(),
            });
        });

    const columns: DataTableColumn<BuilderRow>[] = [
        {
            id: 'name',
            header: 'Builder',
            sortValue: (builder) => builder.name,
            cell: (builder) => (
                <span className="grid min-w-0">
                    <span className="flex items-center gap-2 font-medium">
                        <StatusDot status={builder.enabled ? (builder.online ? 'online' : 'offline') : 'inactive'} />
                        <span className="truncate">{builder.name}</span>
                    </span>
                    {(builder.reported_name || builder.last_ip) && (
                        <span className="text-fg-faint truncate pl-4 font-mono text-xs">
                            {[builder.reported_name !== builder.name ? builder.reported_name : null, builder.last_ip].filter(Boolean).join(' · ')}
                        </span>
                    )}
                </span>
            ),
        },
        {
            id: 'modes',
            header: 'Modes',
            hideOnMobile: true,
            cell: (builder) => (
                <span className="flex gap-1">
                    {builder.modes.map((mode) => (
                        <Tag key={mode}>{mode}</Tag>
                    ))}
                </span>
            ),
        },
        { id: 'status', header: 'Status', cell: builderStatus },
        {
            id: 'seen',
            header: 'Last seen',
            hideOnMobile: true,
            sortValue: (builder) => builder.last_seen_at ?? '',
            cell: (builder) => <RelativeTime value={builder.last_seen_at} fallback="Never" className="text-fg-muted" />,
        },
    ];

    const actions = (builder: BuilderRow): MenuAction[] => [
        ...(builder.kind === 'server' ? [{ label: 'Reinstall', icon: <RefreshCw />, onSelect: () => reinstall(builder) }] : []),
        {
            label: builder.enabled ? 'Disable' : 'Enable',
            icon: <Power />,
            disabled: busy === builder.id,
            onSelect: () => setEnabled(builder, !builder.enabled),
        },
        { type: 'separator' },
        { label: 'Remove', icon: <Trash2 />, danger: true, onSelect: () => setRemoving(builder) },
    ];

    const serveCommand = plainToken ? `KILN_URL=${panelUrl} KILN_BUILDER_TOKEN=${plainToken} kiln-builder serve` : '';
    const installCommand = `curl -fsSL ${panelUrl}/install/builder/linux-amd64 -o /usr/local/bin/kiln-builder && chmod +x /usr/local/bin/kiln-builder`;

    return (
        <SettingsLayout
            title="Builders"
            description="Where sites are built. Builds run on the control plane, on builder servers or on machines you run yourself — app servers only receive finished artifacts."
            wide
        >
            <Section title="Control-plane builder" description="Shared by every organization on this installation; configured through the environment." bare>
                {local.length === 0 ? (
                    <Callout tone={localConfigured ? 'info' : 'warning'} title={localConfigured ? 'Waiting for the local builder' : 'Local builder not configured'}>
                        {localConfigured ? (
                            'The token is set, but the builder process has not checked in yet. Start it next to the control plane.'
                        ) : (
                            <>
                                Set <code className="font-mono text-xs">KILN_LOCAL_BUILDER_TOKEN</code> to build on the control-plane host, or add a builder
                                server below.
                            </>
                        )}
                    </Callout>
                ) : (
                    <DataTable label="Control-plane builder" rows={local} rowKey={(builder) => builder.id} columns={columns} />
                )}
            </Section>

            <Section
                title="Builder servers"
                description="Kiln servers of type Builder. Kiln installs and upgrades kiln-builder on them for you."
                aside={
                    can.manage && (
                        <Button asChild size="sm" icon={<Server />}>
                            <Link href="/servers/create">Add builder server</Link>
                        </Button>
                    )
                }
                bare
            >
                <DataTable
                    label="Builder servers"
                    rows={servers}
                    rowKey={(builder) => builder.id}
                    columns={columns}
                    rowActions={can.manage ? actions : undefined}
                    empty={{
                        icon: <Server />,
                        size: 'sm',
                        title: 'No builder servers',
                        description: 'Create a server and choose the Builder type to keep heavy builds off your app servers.',
                    }}
                />
            </Section>

            <Section title="External builders" description="Run kiln-builder on any machine with Docker (a CI runner, a spare box). It builds only this organization's sites." bare>
                {plainToken && (
                    <Callout tone="success" title="Builder token created — copy it now, it won't be shown again">
                        <div className="mt-1 grid gap-2">
                            <CodeBlock title="1. Install" code={installCommand} wrap />
                            <CodeBlock title="2. Run" code={serveCommand} wrap />
                        </div>
                    </Callout>
                )}
                <DataTable
                    label="External builders"
                    rows={external}
                    rowKey={(builder) => builder.id}
                    columns={columns}
                    rowActions={can.manage ? actions : undefined}
                    empty={{
                        icon: <Hammer />,
                        size: 'sm',
                        title: 'No external builders',
                        description: can.manage ? 'Create a token below, then start kiln-builder with it.' : 'Ask an admin to create a builder token.',
                    }}
                />
                {can.manage && (
                    <form onSubmit={submit} className="border-border bg-surface-1 grid gap-4 rounded-lg border p-4">
                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <Field label="Name" error={form.errors.name} required>
                                <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="ci-runner-1" />
                            </Field>
                            <Button type="submit" variant="primary" icon={<Plus />} loading={form.processing} disabled={form.data.modes.length === 0}>
                                Create token
                            </Button>
                        </div>
                        <fieldset className="flex flex-wrap gap-x-6 gap-y-2">
                            <legend className="text-fg mb-2 text-xs font-medium">Build modes</legend>
                            {MODES.map((mode) => (
                                <Field key={mode.value} inline label={mode.label} hint={mode.hint}>
                                    <Checkbox
                                        checked={form.data.modes.includes(mode.value)}
                                        onCheckedChange={(checked) => toggleMode(mode.value, checked === true)}
                                    />
                                </Field>
                            ))}
                        </fieldset>
                        {(form.errors.modes || form.data.modes.length === 0) && (
                            <p className="text-danger text-xs">{form.errors.modes ?? 'Pick at least one build mode.'}</p>
                        )}
                    </form>
                )}
            </Section>

            {builders.length === 0 && !localConfigured && !can.manage && (
                <EmptyState icon={<Hammer />} title="No builders" description="Builds will queue until a builder connects." />
            )}

            <ConfirmDestructive
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.name ?? ''}`}
                description="Its token stops working immediately; queued builds move to another builder."
                confirmText={removing?.name ?? ''}
                confirmLabel="Remove builder"
                onConfirm={remove}
            />
        </SettingsLayout>
    );
}
