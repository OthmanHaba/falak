import { CommandLog } from '@/components/command-log';
import { Button, IconButton } from '@/components/falak/button';
import { ChangesBar } from '@/components/falak/changes-bar';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Menu } from '@/components/falak/menu';
import { Section } from '@/components/falak/section';
import { Select } from '@/components/falak/select';
import { StatusBadge } from '@/components/falak/status';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { cn } from '@/lib/utils';
import { router, useForm, usePoll } from '@inertiajs/react';
import { Code2, Plus, Star, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { type FpmSettings, type PhpVersionRow } from '../../types';

interface Props {
    server: ServerHeader;
    runtime: string | null;
    php: PhpVersionRow[];
    phpOptions: string[];
    can: { update: boolean };
}

const STATUS: Record<PhpVersionRow['status'], { status: string; label: string }> = {
    installing: { status: 'running', label: 'Installing' },
    installed: { status: 'active', label: 'Installed' },
    failed: { status: 'failed', label: 'Failed' },
    removing: { status: 'running', label: 'Removing' },
};

type IniRow = { key: string; value: string };
type SettingsForm = { ini: IniRow[]; fpm: FpmSettings };

const FPM_FIELDS: { key: Exclude<keyof FpmSettings, 'pm'>; label: string; hint: string }[] = [
    { key: 'max_children', label: 'Max children', hint: 'Upper bound of worker processes.' },
    { key: 'start_servers', label: 'Start servers', hint: 'Workers created at start (dynamic).' },
    { key: 'min_spare_servers', label: 'Min spare', hint: 'Idle workers kept ready.' },
    { key: 'max_spare_servers', label: 'Max spare', hint: 'Idle workers above this are killed.' },
    { key: 'max_requests', label: 'Max requests', hint: 'Recycle a worker after N requests (0 = never).' },
];

function toRows(php: PhpVersionRow): IniRow[] {
    return Object.entries(php.ini).map(([key, value]) => ({ key, value: String(value) }));
}

/** php.ini overrides + FPM pool defaults of one version, edited in place with a sticky "unsaved changes" bar. */
function VersionSettings({ server, php, canUpdate }: { server: ServerHeader; php: PhpVersionRow; canUpdate: boolean }) {
    const initial = useMemo<SettingsForm>(() => ({ ini: toRows(php), fpm: php.fpm }), [php]);
    const form = useForm<SettingsForm>(initial);
    const errorBag = form.errors as Partial<Record<string, string>>;
    const disabled = !canUpdate || php.status !== 'installed';

    useEffect(() => {
        form.setDefaults(initial);
        form.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initial]);

    const changes = useMemo(() => {
        let count = 0;
        const before = new Map(initial.ini.map((row) => [row.key, row.value]));
        const after = new Map(form.data.ini.filter((row) => row.key.trim() !== '').map((row) => [row.key.trim(), row.value]));
        after.forEach((value, key) => (before.get(key) !== value ? count++ : null));
        before.forEach((_, key) => (!after.has(key) ? count++ : null));
        (Object.keys(initial.fpm) as (keyof FpmSettings)[]).forEach((key) => (initial.fpm[key] !== form.data.fpm[key] ? count++ : null));

        return count;
    }, [form.data, initial]);

    const setIni = (index: number, patch: Partial<IniRow>) =>
        form.setData(
            'ini',
            form.data.ini.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
    const setFpm = <K extends keyof FpmSettings>(key: K, value: FpmSettings[K]) => form.setData('fpm', { ...form.data.fpm, [key]: value });

    const save = () => {
        form.transform((data) => ({
            ini: Object.fromEntries(data.ini.filter((row) => row.key.trim() !== '').map((row) => [row.key.trim(), row.value])),
            fpm: data.fpm,
        }));
        form.put(route('servers.php.settings', [server.id, php.version]), {
            preserveScroll: true,
            onSuccess: () => toast.success(`PHP ${php.version} settings saved`, 'php.ini changes are being applied.'),
            onError: () => toast.error('Some settings need attention'),
        });
    };

    return (
        <div className="grid gap-6">
            <Section
                title="php.ini overrides"
                description="Applied to this version right away (CLI and FPM)."
                aside={
                    !disabled && (
                        <Button
                            variant="ghost"
                            size="sm"
                            icon={<Plus />}
                            onClick={() => form.setData('ini', [...form.data.ini, { key: '', value: '' }])}
                        >
                            Add directive
                        </Button>
                    )
                }
            >
                {form.data.ini.length === 0 ? (
                    <p className="text-fg-muted text-sm">No overrides — PHP defaults apply.</p>
                ) : (
                    <div className="grid gap-2">
                        {form.data.ini.map((row, index) => (
                            <div key={index} className="flex items-center gap-2">
                                <Input
                                    aria-label="Directive"
                                    value={row.key}
                                    onChange={(event) => setIni(index, { key: event.target.value })}
                                    placeholder="memory_limit"
                                    mono
                                    disabled={disabled}
                                    className="sm:max-w-64"
                                />
                                <span className="text-fg-faint" aria-hidden>
                                    =
                                </span>
                                <Input
                                    aria-label={`Value of ${row.key || 'directive'}`}
                                    value={row.value}
                                    onChange={(event) => setIni(index, { value: event.target.value })}
                                    placeholder="512M"
                                    mono
                                    disabled={disabled}
                                />
                                {!disabled && (
                                    <IconButton
                                        label="Remove directive"
                                        icon={<X />}
                                        size="sm"
                                        onClick={() =>
                                            form.setData(
                                                'ini',
                                                form.data.ini.filter((_, i) => i !== index),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </div>
                )}
                {errorBag.ini && <p className="text-danger text-xs">{errorBag.ini}</p>}
            </Section>

            <Section title="FPM pool defaults" description="Used when Falak creates a new site pool on this version.">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Process manager" hint="How the pool scales workers.">
                        <Select
                            value={form.data.fpm.pm}
                            onValueChange={(value) => setFpm('pm', value)}
                            disabled={disabled}
                            options={[
                                { value: 'dynamic', label: 'dynamic', description: 'Scale between spare bounds' },
                                { value: 'static', label: 'static', description: 'Always max children' },
                                { value: 'ondemand', label: 'ondemand', description: 'Spawn on request' },
                            ]}
                        />
                    </Field>
                    {FPM_FIELDS.map((field) => (
                        <Field key={field.key} label={field.label} hint={field.hint} error={errorBag[`fpm.${field.key}`]}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.fpm[field.key]}
                                onChange={(event) => setFpm(field.key, Number(event.target.value))}
                                disabled={disabled}
                                className="tabular"
                            />
                        </Field>
                    ))}
                </div>
            </Section>

            {changes > 0 && (
                <ChangesBar
                    count={changes}
                    message={`Save to apply to PHP ${php.version}`}
                    applyLabel="Save"
                    onApply={save}
                    onDiscard={() => form.reset()}
                    processing={form.processing}
                />
            )}
        </div>
    );
}

export default function Php({ server, runtime, php, phpOptions, can }: Props) {
    const [install, setInstall] = useState<string>('');
    const [installing, setInstalling] = useState(false);
    const [removing, setRemoving] = useState<PhpVersionRow | null>(null);
    const [selected, setSelected] = useState<string | null>(() => php.find((row) => row.is_default)?.version ?? php[0]?.version ?? null);
    const busy = php.some((row) => row.status === 'installing' || row.status === 'removing');
    const active = server.status === 'active';

    usePoll(busy ? 3_000 : 60_000, { only: ['php', 'phpOptions', 'server'] });

    const current = php.find((row) => row.version === selected) ?? php[0];

    const installVersion = () => {
        if (!install) return;
        router.post(
            route('servers.php.store', server.id),
            { version: install },
            {
                preserveScroll: true,
                onStart: () => setInstalling(true),
                onFinish: () => setInstalling(false),
                onSuccess: () => {
                    toast.success(`Installing PHP ${install}`, 'This takes a minute or two.');
                    setSelected(install);
                    setInstall('');
                },
                onError: (errors) => toast.error('Could not install', Object.values(errors)[0]),
            },
        );
    };

    const makeDefault = (row: PhpVersionRow) =>
        router.put(
            route('servers.php.default', [server.id, row.version]),
            {},
            { preserveScroll: true, onSuccess: () => toast.success(`PHP ${row.version} is now the default`) },
        );

    const remove = () =>
        new Promise<void>((resolve) => {
            if (!removing) return resolve();
            router.delete(route('servers.php.destroy', [server.id, removing.version]), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Removing PHP ${removing.version}`);
                    setRemoving(null);
                },
                onFinish: () => resolve(),
            });
        });

    return (
        <ServerLayout
            server={server}
            tab="php"
            reloadOnly={['server', 'php', 'phpOptions']}
            actions={
                can.update &&
                active &&
                phpOptions.length > 0 && (
                    <div className="flex items-center gap-2">
                        <Select
                            value={install || undefined}
                            onValueChange={setInstall}
                            placeholder="Version"
                            aria-label="PHP version to install"
                            className="w-28"
                            options={phpOptions.map((version) => ({ value: version, label: `PHP ${version}` }))}
                        />
                        <Button variant="primary" icon={<Plus />} onClick={installVersion} disabled={!install} loading={installing}>
                            Install
                        </Button>
                    </div>
                )
            }
        >
            {php.length === 0 ? (
                <EmptyState
                    icon={<Code2 />}
                    title="No PHP on this server"
                    description={
                        runtime
                            ? 'Install a PHP version to host PHP sites here. Each version gets its own php.ini and FPM pool defaults.'
                            : "This server's stack has no PHP runtime. PHP sites need a server with FrankenPHP or PHP-FPM."
                    }
                    action={
                        can.update && active && runtime && phpOptions.length > 0 ? (
                            <span className="text-fg-muted text-xs">Pick a version above and click Install.</span>
                        ) : undefined
                    }
                />
            ) : (
                <div className="grid gap-6 md:grid-cols-[220px_minmax(0,1fr)]">
                    <div className="grid content-start gap-2">
                        <p className="text-2xs text-fg-faint px-1 font-medium tracking-wide uppercase">
                            {runtime === 'fpm' ? 'PHP-FPM + Caddy' : runtime === 'frankenphp' ? 'FrankenPHP' : 'Versions'}
                        </p>
                        <ul className="grid gap-1" aria-label="PHP versions">
                            {php.map((row) => {
                                const status = STATUS[row.status];

                                return (
                                    <li key={row.id}>
                                        <div
                                            className={cn(
                                                'group flex items-center gap-2 rounded-md border px-2.5 py-2 transition-colors duration-150',
                                                current?.id === row.id
                                                    ? 'border-border-strong bg-surface-2'
                                                    : 'hover:bg-surface-2 border-transparent',
                                            )}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => setSelected(row.version)}
                                                aria-current={current?.id === row.id ? 'true' : undefined}
                                                className="flex min-w-0 flex-1 items-center gap-2 text-left"
                                            >
                                                <span className="text-fg text-sm font-medium">PHP {row.version}</span>
                                                {row.is_default && (
                                                    <Tag tone="accent" icon={<Star />}>
                                                        default
                                                    </Tag>
                                                )}
                                            </button>
                                            {row.status !== 'installed' && <StatusBadge status={status.status} label={status.label} />}
                                            {can.update && (
                                                <Menu
                                                    label={`Actions for PHP ${row.version}`}
                                                    actions={[
                                                        {
                                                            label: 'Make default',
                                                            icon: <Star />,
                                                            disabled: row.status !== 'installed' || row.is_default,
                                                            onSelect: () => makeDefault(row),
                                                        },
                                                        { type: 'separator' },
                                                        {
                                                            label: 'Remove…',
                                                            icon: <Trash2 />,
                                                            danger: true,
                                                            disabled: row.is_default || row.status === 'installing' || row.status === 'removing',
                                                            onSelect: () => setRemoving(row),
                                                        },
                                                    ]}
                                                />
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    {current && (
                        <div className="grid min-w-0 content-start gap-6">
                            {current.status_message && (
                                <p role="alert" className="border-danger/40 bg-danger-soft text-danger rounded-lg border px-4 py-3 text-sm">
                                    {current.status_message}
                                </p>
                            )}
                            {current.command_id &&
                                (current.status === 'installing' || current.status === 'removing' || current.status === 'failed') && (
                                    <Section title={current.status === 'failed' ? 'Last run' : 'Progress'} bare>
                                        <CommandLog commandId={current.command_id} />
                                    </Section>
                                )}
                            <VersionSettings key={current.id} server={server} php={current} canUpdate={can.update} />
                        </div>
                    )}
                </div>
            )}

            <ConfirmDestructive
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove PHP ${removing?.version}?`}
                description="The packages are uninstalled from the server. Sites still using this version stop working."
                confirmText={removing ? `php${removing.version}` : ''}
                confirmLabel="Remove version"
                onConfirm={remove}
            />
        </ServerLayout>
    );
}
