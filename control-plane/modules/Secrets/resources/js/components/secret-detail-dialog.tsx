import {
    Button,
    Callout,
    ConfirmDestructive,
    CopyButton,
    DataTable,
    Dialog,
    EmptyState,
    Field,
    Input,
    KeyValue,
    RelativeTime,
    SecretInput,
    Select,
    Switch,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    Tag,
    Textarea,
    toast,
} from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { Link } from '@inertiajs/react';
import { Eye, EyeOff, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import {
    ON_CHANGE_LABELS,
    SCOPE_LABELS,
    type OnChange,
    type ProviderOption,
    type SecretAbilities,
    type SecretDetail,
    type SecretRow,
} from '../types';
import { ReauthDialog } from './reauth-dialog';

interface Revealed {
    version: number;
    value: string;
}

/**
 * One secret: metadata, a new value, versions (roll back / disable), the access log and the services using it.
 * Values only appear after an explicit, re-authenticated reveal (never for sensitive secrets).
 */
export function SecretDetailDialog({
    secret,
    providers,
    can,
    reauthRequiresCode,
    onClose,
    onChanged,
}: {
    secret: SecretRow | null;
    providers: ProviderOption[];
    can: SecretAbilities;
    reauthRequiresCode: boolean;
    onClose: () => void;
    onChanged: () => void;
}) {
    const url = secret ? `/secrets/${secret.id}` : null;
    const { data, error, reload } = useJson<SecretDetail>(url);
    const detail = data ?? (secret as SecretDetail | null);

    const [meta, setMeta] = useState({
        description: '',
        rotation_days: '',
        sensitive: true,
        available_to_previews: false,
        provider_id: '',
        watch_minutes: '',
        on_change: 'none' as OnChange,
    });
    const [value, setValue] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [revealed, setRevealed] = useState<Revealed | null>(null);
    const [reauth, setReauth] = useState<{ version: number | null } | null>(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        if (!secret) return;
        setMeta({
            description: secret.description ?? '',
            rotation_days: secret.rotation_days ? String(secret.rotation_days) : '',
            sensitive: secret.sensitive,
            available_to_previews: secret.available_to_previews,
            provider_id: secret.provider_id ?? '',
            watch_minutes: secret.watch_minutes ? String(secret.watch_minutes) : '',
            on_change: secret.on_change,
        });
        setValue('');
        setErrors({});
        setRevealed(null);
    }, [secret]);

    if (!secret || !detail) return null;

    const run = async (key: string, action: () => Promise<unknown>, success: string) => {
        setBusy(key);
        setErrors({});
        try {
            await action();
            toast.success(success);
            await reload();
            onChanged();
        } catch (e) {
            if (e instanceof HttpError && Object.keys(e.errors).length > 0) setErrors(e.errors);
            else toast.error(errorMessage(e));
        } finally {
            setBusy(null);
        }
    };

    const saveMeta = (event: FormEvent) => {
        event.preventDefault();
        void run(
            'meta',
            () =>
                requestJson(`/secrets/${secret.id}`, 'PATCH', {
                    description: meta.description || null,
                    rotation_days: meta.rotation_days ? Number(meta.rotation_days) : null,
                    sensitive: meta.sensitive,
                    available_to_previews: meta.available_to_previews,
                    ...(secret.kind === 'linked'
                        ? {
                              provider_id: meta.provider_id || null,
                              watch_minutes: meta.watch_minutes ? Number(meta.watch_minutes) : null,
                              on_change: meta.on_change,
                          }
                        : {}),
                }),
            'Secret updated',
        );
    };

    const saveValue = (event: FormEvent) => {
        event.preventDefault();
        void run('value', () => requestJson(`/secrets/${secret.id}/versions`, 'POST', { value }), 'New version saved').then(() => setValue(''));
    };

    const reveal = async (version: number | null) => {
        setBusy(`reveal-${version ?? 'current'}`);
        try {
            const response = await requestJson<{ data: Revealed }>(`/secrets/${secret.id}/reveal`, 'POST', version ? { version } : {});
            setRevealed(response.data);
            void reload();
        } catch (e) {
            if (e instanceof HttpError && e.status === 423) setReauth({ version });
            else toast.error(errorMessage(e));
        } finally {
            setBusy(null);
        }
    };

    const linked = detail.kind === 'linked';
    const versions = data?.versions ?? [];
    const access = data?.access_log ?? [];
    const usedBy = detail.used_by ?? [];
    const pinned = versions.find((version) => version.current)?.pinned ?? false;
    const provider = providers.find((option) => option.id === detail.provider_id);

    return (
        <>
            <Dialog
                open={secret !== null}
                onOpenChange={(open) => !open && onClose()}
                size="lg"
                title={<span className="font-mono">{detail.name}</span>}
                description={
                    <span className="flex flex-wrap items-center gap-1.5">
                        <Tag>{SCOPE_LABELS[detail.scope]}</Tag>
                        <span>{detail.scope_label}</span>
                        {detail.sensitive && <Tag tone="warning">Sensitive</Tag>}
                        {linked && <Tag tone="info">{provider ? `Linked · ${provider.name}` : 'Linked'}</Tag>}
                        {pinned && <Tag tone="warning">Pinned</Tag>}
                        <span className="text-fg-faint">· v{detail.current_version}</span>
                    </span>
                }
            >
                {error && <Callout tone="danger">{error}</Callout>}
                <Tabs defaultValue="overview" className="grid gap-4">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="versions" badge={versions.length || undefined}>
                            Versions
                        </TabsTrigger>
                        <TabsTrigger value="access">Access log</TabsTrigger>
                        <TabsTrigger value="usage" badge={usedBy.length || undefined}>
                            Used by
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="grid gap-6">
                        {pinned && (
                            <Callout tone="warning">
                                Rolled back to a recorded value: deployments use it instead of asking the provider, and the watch is paused. Save the
                                reference again to follow the provider.
                            </Callout>
                        )}
                        <KeyValue
                            columns={3}
                            items={[
                                { label: 'Reference', value: `\${{ secrets.${detail.name} }}`, copy: `\${{ secrets.${detail.name} }}`, mono: true },
                                { label: 'Last accessed', value: <RelativeTime value={detail.last_accessed_at} fallback="Never" /> },
                                {
                                    label: 'Rotation',
                                    value: detail.rotation_due_at ? (
                                        <span>
                                            due <RelativeTime value={detail.rotation_due_at} />
                                        </span>
                                    ) : (
                                        'No policy'
                                    ),
                                },
                            ]}
                        />

                        <div className="grid gap-2">
                            <p className="text-sm font-medium">{linked ? 'Reference' : 'Value'}</p>
                            {detail.sensitive ? (
                                <p className="text-fg-muted text-sm">Sensitive secrets are write-only: the value can be replaced, never read back.</p>
                            ) : revealed ? (
                                <div className="flex items-center gap-1.5">
                                    <Input mono readOnly value={revealed.value} aria-label={`Value of version ${revealed.version}`} />
                                    <CopyButton value={revealed.value} />
                                    <Button size="sm" variant="ghost" icon={<EyeOff />} onClick={() => setRevealed(null)}>
                                        Hide
                                    </Button>
                                </div>
                            ) : can.reveal ? (
                                <div>
                                    <Button size="sm" icon={<Eye />} loading={busy === 'reveal-current'} onClick={() => void reveal(null)}>
                                        Reveal
                                    </Button>
                                </div>
                            ) : (
                                <p className="text-fg-muted text-sm">You don't have permission to reveal secrets.</p>
                            )}
                        </div>

                        {can.manage && (
                            <form onSubmit={saveValue} className="grid gap-2">
                                <Field
                                    label={linked ? 'New reference' : 'New value'}
                                    hint="Saved as a new version; deployments use it from their next run."
                                    error={errors.value ?? errors.reference}
                                >
                                    {linked ? (
                                        <Input
                                            mono
                                            value={value}
                                            onChange={(event) => setValue(event.target.value)}
                                            placeholder="vault://kv/data/app#KEY"
                                        />
                                    ) : (
                                        <SecretInput value={value} onChange={setValue} />
                                    )}
                                </Field>
                                <div>
                                    <Button size="sm" variant="primary" type="submit" disabled={value === ''} loading={busy === 'value'}>
                                        Save new version
                                    </Button>
                                </div>
                            </form>
                        )}

                        <form onSubmit={saveMeta} className="grid gap-4">
                            <Field label="Description" error={errors.description}>
                                <Textarea
                                    rows={2}
                                    value={meta.description}
                                    disabled={!can.manage}
                                    onChange={(event) => setMeta({ ...meta, description: event.target.value })}
                                />
                            </Field>
                            {linked && (
                                <>
                                    <Field label="Provider" error={errors.provider_id}>
                                        <Select
                                            value={meta.provider_id}
                                            disabled={!can.manage}
                                            onValueChange={(value) => setMeta({ ...meta, provider_id: value })}
                                            options={providers.map((option) => ({
                                                value: option.id,
                                                label: option.name,
                                                description: `${option.scheme}://`,
                                            }))}
                                        />
                                    </Field>
                                    <div className="grid items-start gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Watch every (minutes)"
                                            hint={detail.last_polled_at ? undefined : 'Empty: not watched.'}
                                            error={errors.watch_minutes}
                                        >
                                            <Input
                                                type="number"
                                                min={1}
                                                max={1440}
                                                value={meta.watch_minutes}
                                                disabled={!can.manage}
                                                placeholder="Off"
                                                onChange={(event) => setMeta({ ...meta, watch_minutes: event.target.value })}
                                            />
                                        </Field>
                                        <Field label="When it changes" error={errors.on_change}>
                                            <Select
                                                value={meta.on_change}
                                                disabled={!can.manage || !meta.watch_minutes}
                                                onValueChange={(value) => setMeta({ ...meta, on_change: value as OnChange })}
                                                options={(Object.keys(ON_CHANGE_LABELS) as OnChange[]).map((value) => ({
                                                    value,
                                                    label: ON_CHANGE_LABELS[value],
                                                }))}
                                            />
                                        </Field>
                                    </div>
                                    {detail.last_polled_at && (
                                        <p className="text-fg-faint -mt-2 text-xs">
                                            Last checked <RelativeTime value={detail.last_polled_at} />
                                        </p>
                                    )}
                                </>
                            )}
                            <Field label="Rotate every (days)" error={errors.rotation_days}>
                                <Input
                                    type="number"
                                    min={1}
                                    max={3650}
                                    value={meta.rotation_days}
                                    disabled={!can.manage}
                                    placeholder="Never"
                                    onChange={(event) => setMeta({ ...meta, rotation_days: event.target.value })}
                                />
                            </Field>
                            <Field label="Sensitive" inline hint="Can be turned on, never off." error={errors.sensitive}>
                                <Switch
                                    checked={meta.sensitive}
                                    disabled={!can.manage || detail.sensitive}
                                    onCheckedChange={(checked) => setMeta({ ...meta, sensitive: checked })}
                                />
                            </Field>
                            <Field label="Available to preview environments" inline>
                                <Switch
                                    checked={meta.available_to_previews}
                                    disabled={!can.manage}
                                    onCheckedChange={(checked) => setMeta({ ...meta, available_to_previews: checked })}
                                />
                            </Field>
                            {can.manage && (
                                <div className="flex items-center justify-between gap-2">
                                    <Button size="sm" type="submit" loading={busy === 'meta'}>
                                        Save details
                                    </Button>
                                    <Button size="sm" variant="ghost" className="text-danger" icon={<Trash2 />} onClick={() => setDeleting(true)}>
                                        Delete secret
                                    </Button>
                                </div>
                            )}
                        </form>
                    </TabsContent>

                    <TabsContent value="versions">
                        {errors.version && (
                            <Callout tone="danger" className="mb-3">
                                {errors.version}
                            </Callout>
                        )}
                        <DataTable
                            label="Versions"
                            rows={versions}
                            rowKey={(row) => String(row.version)}
                            loading={data === null}
                            columns={[
                                {
                                    id: 'version',
                                    header: 'Version',
                                    cell: (row) => (
                                        <span className="flex items-center gap-1.5">
                                            <span className="tabular font-mono">v{row.version}</span>
                                            {row.current && <Tag tone="success">Current</Tag>}
                                            {row.disabled_at && <Tag tone="faint">Disabled</Tag>}
                                            {row.pinned && <Tag tone="warning">Pinned</Tag>}
                                            {row.restored_from && <span className="text-fg-faint text-xs">from v{row.restored_from}</span>}
                                            {row.note && !row.restored_from && <span className="text-fg-faint text-xs">{row.note}</span>}
                                        </span>
                                    ),
                                },
                                { id: 'by', header: 'By', cell: (row) => row.created_by ?? 'API / system' },
                                { id: 'when', header: 'When', cell: (row) => <RelativeTime value={row.created_at} /> },
                            ]}
                            rowActions={(row) => [
                                ...(!detail.sensitive && can.reveal && !row.disabled_at
                                    ? [{ label: 'Reveal', onSelect: () => void reveal(row.version) }]
                                    : []),
                                ...(can.manage && !row.current && !row.disabled_at
                                    ? [
                                          {
                                              label: `Roll back to v${row.version}`,
                                              icon: <RotateCcw className="size-4" />,
                                              onSelect: () =>
                                                  void run(
                                                      'restore',
                                                      () => requestJson(`/secrets/${secret.id}/versions/${row.version}/restore`, 'POST'),
                                                      `Rolled back to v${row.version} (as a new version)`,
                                                  ),
                                          },
                                          {
                                              label: 'Disable version',
                                              danger: true,
                                              onSelect: () =>
                                                  void run(
                                                      'disable',
                                                      () => requestJson(`/secrets/${secret.id}/versions/${row.version}/disable`, 'POST'),
                                                      `v${row.version} disabled`,
                                                  ),
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    </TabsContent>

                    <TabsContent value="access">
                        <DataTable
                            label="Access log"
                            rows={access}
                            rowKey={(row) => String(row.id)}
                            loading={data === null}
                            empty={{ title: 'No reads yet', description: 'Deployments, reveals and API reads appear here.', size: 'sm' }}
                            columns={[
                                { id: 'when', header: 'When', cell: (row) => <RelativeTime value={row.created_at} /> },
                                { id: 'who', header: 'Who', cell: (row) => row.actor },
                                { id: 'why', header: 'Reason', cell: (row) => row.reason },
                                { id: 'version', header: 'Version', cell: (row) => <span className="tabular font-mono">v{row.version}</span> },
                                {
                                    id: 'ip',
                                    header: 'IP',
                                    cell: (row) => <span className="font-mono text-xs">{row.ip ?? '—'}</span>,
                                    hideOnMobile: true,
                                },
                            ]}
                        />
                    </TabsContent>

                    <TabsContent value="usage">
                        {usedBy.length === 0 ? (
                            <EmptyState
                                size="sm"
                                title="Not referenced"
                                description={`No service in this scope references \${{ secrets.${detail.name} }} (or a nearer secret of the same name shadows it).`}
                            />
                        ) : (
                            <ul className="divide-border grid divide-y">
                                {usedBy.map((user) => (
                                    <li key={user.service_id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                        {user.url ? (
                                            <Link href={user.url} className="hover:text-primary font-medium">
                                                {user.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">{user.name}</span>
                                        )}
                                        <span className="text-fg-muted font-mono text-xs">{user.variables.join(', ')}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </TabsContent>
                </Tabs>
            </Dialog>

            <ReauthDialog
                open={reauth !== null}
                requiresCode={reauthRequiresCode}
                onClose={() => setReauth(null)}
                onConfirmed={() => {
                    const version = reauth?.version ?? null;
                    setReauth(null);
                    void reveal(version);
                }}
            />

            <ConfirmDestructive
                open={deleting}
                onOpenChange={setDeleting}
                title={`Delete ${detail.name}?`}
                description={
                    usedBy.length > 0
                        ? `${usedBy.length} service(s) reference it; their next deployment fails until the reference is removed or the secret recreated.`
                        : 'Every version is deleted. The access log is kept.'
                }
                confirmText={detail.name}
                confirmLabel="Delete secret"
                processing={busy === 'delete'}
                onConfirm={() =>
                    run('delete', () => requestJson(`/secrets/${secret.id}`, 'DELETE'), 'Secret deleted').then(() => {
                        setDeleting(false);
                        onClose();
                    })
                }
            />
        </>
    );
}
