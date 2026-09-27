import {
    Button,
    Callout,
    Checkbox,
    CodeBlock,
    ConfirmDestructive,
    Field,
    Input,
    KeyValue,
    Section,
    Select,
    SkeletonRows,
    StatusBadge,
    Switch,
    Tag,
    toast,
} from '@/components/kiln';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { KeyRound, RotateCw, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useSave, useSiteSettings, type SiteSettingsData } from './data';

const NONE = '__none__';

function Loading({ error }: { error: string | null }) {
    return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={4} />;
}

/** Form state that re-syncs whenever the saved value changes (compared by content). */
function useDraft<T extends object>(source: T | null): [T | null, (patch: Partial<T>) => void, () => void, boolean] {
    const key = JSON.stringify(source);
    const [draft, setDraft] = useState<T | null>(source);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => setDraft(source), [key]);

    return [
        draft,
        (patch) => setDraft((current) => (current ? { ...current, ...patch } : current)),
        () => setDraft(source),
        JSON.stringify(draft) !== key,
    ];
}

// ─── Source ──────────────────────────────────────────────────────────────────────────────────────────────────────

/** Repository + branch + git connection, and the deploy key the servers pull with. */
export function SourceSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useSiteSettings(ctx);
    const source = data
        ? {
              source_connection_id: data.settings.source_connection_id ?? '',
              repository: data.settings.repository ?? '',
              branch: data.settings.branch ?? '',
          }
        : null;
    const [value, set, reset, dirty] = useDraft(source);
    const { saving, errors, save } = useSave(reload, ctx.refresh);

    if (!data || !value) return <Loading error={error} />;
    const custom = data.options.connections.find((item) => item.id === value.source_connection_id)?.provider === 'custom';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void save(
            'PATCH',
            `/sites/${data.site.id}`,
            {
                source_connection_id: value.source_connection_id || null,
                repository: value.source_connection_id ? value.repository || null : null,
                branch: value.source_connection_id ? value.branch || null : null,
            },
            'Source saved',
        );
    };

    return (
        <>
            <Section
                title="Repository"
                description="Deploys build this branch. Changing the repository installs a new deploy key."
                footer={
                    data.can.update && (
                        <>
                            <Button variant="ghost" disabled={!dirty || saving} onClick={reset}>
                                Reset
                            </Button>
                            <Button variant="primary" type="submit" form="site-source" loading={saving} disabled={!dirty}>
                                Save
                            </Button>
                        </>
                    )
                }
            >
                {!data.settings.source_connection_id && data.settings.repository && (
                    <Callout tone="info" title={`${data.settings.repository} · ${data.settings.branch ?? 'main'}`}>
                        No git connection is linked, so deploys use a manually installed key. Pick a connection to manage the deploy key and push to
                        deploy.
                    </Callout>
                )}
                <form id="site-source" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Git connection" error={errors.source_connection_id} className="sm:col-span-2">
                        <Select
                            value={value.source_connection_id || NONE}
                            disabled={!data.can.update}
                            onValueChange={(next) => set({ source_connection_id: next === NONE ? '' : next })}
                            options={[
                                { value: NONE, label: 'No repository (upload / manual deploys)' },
                                ...data.options.connections.map((item) => ({ value: item.id, label: `${item.name} · ${item.provider_label}` })),
                            ]}
                        />
                    </Field>
                    {value.source_connection_id && (
                        <>
                            <Field label={custom ? 'Clone URL' : 'Repository'} error={errors.repository}>
                                <Input
                                    mono
                                    value={value.repository}
                                    disabled={!data.can.update}
                                    placeholder={custom ? 'git@git.example.com:acme/shop.git' : 'acme/shop'}
                                    onChange={(event) => set({ repository: event.target.value })}
                                />
                            </Field>
                            <Field label="Branch" error={errors.branch}>
                                <Input
                                    mono
                                    value={value.branch}
                                    disabled={!data.can.update}
                                    placeholder="main"
                                    onChange={(event) => set({ branch: event.target.value })}
                                />
                            </Field>
                        </>
                    )}
                </form>
            </Section>

            <Section
                title="Deploy key"
                description="Read-only SSH key the servers use to fetch the repository."
                aside={
                    data.source.deploy_key &&
                    (data.source.deploy_key.installed ? (
                        <StatusBadge status="active" label="Installed" />
                    ) : (
                        <StatusBadge status="queued" label="Add it to the repository" />
                    ))
                }
            >
                {data.source.error && <Callout tone="warning">Source control is unavailable: {data.source.error}</Callout>}
                {data.source.deploy_key ? (
                    <>
                        <CodeBlock code={data.source.deploy_key.public_key} wrap copyable title="Public key" />
                        <KeyValue
                            items={[
                                { label: 'Fingerprint', value: <span className="font-mono text-xs">{data.source.deploy_key.fingerprint}</span> },
                                {
                                    label: 'Connection',
                                    value: data.source.connection ? `${data.source.connection.name} · ${data.source.connection.provider_label}` : '—',
                                },
                            ]}
                        />
                        {data.source.deploy_key.install_error && <Callout tone="warning">{data.source.deploy_key.install_error}</Callout>}
                    </>
                ) : (
                    <p className="text-fg-muted flex items-center gap-2 text-sm">
                        <KeyRound className="size-4" aria-hidden />{' '}
                        {data.settings.repository
                            ? 'No deploy key managed by Kiln — link a git connection to create one.'
                            : 'No deploy key — this site has no repository.'}
                    </p>
                )}
            </Section>
        </>
    );
}

// ─── Build ───────────────────────────────────────────────────────────────────────────────────────────────────────

type BuildDraft = Pick<
    SiteSettingsData['settings'],
    | 'name'
    | 'runtime'
    | 'build_mode'
    | 'php_version'
    | 'node_version'
    | 'web_directory'
    | 'app_port'
    | 'docker_image'
    | 'dockerfile'
    | 'compose_file'
    | 'health_check_path'
>;

/** Runtime, build mode, language versions and paths. */
export function BuildSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useSiteSettings(ctx);
    const [draft, set, reset, dirty] = useDraft<BuildDraft>(
        data
            ? (({
                  name,
                  runtime,
                  build_mode,
                  php_version,
                  node_version,
                  web_directory,
                  app_port,
                  docker_image,
                  dockerfile,
                  compose_file,
                  health_check_path,
              }) => ({
                  name,
                  runtime,
                  build_mode,
                  php_version,
                  node_version,
                  web_directory,
                  app_port,
                  docker_image,
                  dockerfile,
                  compose_file,
                  health_check_path,
              }))(data.settings)
            : null,
    );
    const { saving, errors, save } = useSave(reload, ctx.refresh);

    if (!data || !draft) return <Loading error={error} />;
    const { options, settings } = data;
    const framework = options.frameworks.find((item) => item.value === settings.framework);
    const current = options.runtimes.find((item) => item.value === settings.runtime);
    const runtime = options.runtimes.find((item) => item.value === draft.runtime) ?? current ?? options.runtimes[0];
    const runtimes = options.runtimes.filter((item) => framework?.runtimes.includes(item.value) && item.container === current?.container);
    const text = (key: keyof BuildDraft) => (draft[key] === null || draft[key] === undefined ? '' : String(draft[key]));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void save(
            'PATCH',
            `/sites/${data.site.id}`,
            {
                runtime: draft.runtime,
                build_mode: draft.build_mode,
                php_version: runtime.is_php ? draft.php_version : null,
                node_version: draft.node_version || null,
                web_directory: draft.web_directory || null,
                app_port: runtime.proxies && draft.app_port ? Number(draft.app_port) : null,
                docker_image: draft.docker_image || null,
                dockerfile: draft.dockerfile || null,
                compose_file: draft.compose_file || null,
                health_check_path: draft.health_check_path || null,
            },
            'Build settings saved',
        );
    };

    return (
        <Section
            title="Build & runtime"
            description={
                <>
                    {settings.framework_label} · runs as <code className="font-mono text-xs">{settings.unix_user}</code>
                    {settings.isolated ? ' (isolated)' : ''} in <code className="font-mono text-xs">{settings.root_path}</code>. Runtime changes
                    reconfigure the servers right away; the rest applies on the next deploy.
                </>
            }
            footer={
                data.can.update && (
                    <>
                        <Button variant="ghost" disabled={!dirty || saving} onClick={reset}>
                            Reset
                        </Button>
                        <Button variant="primary" type="submit" form="site-build" loading={saving} disabled={!dirty}>
                            Save
                        </Button>
                    </>
                )
            }
        >
            <form id="site-build" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="Runtime" error={errors.runtime}>
                    <Select
                        value={draft.runtime}
                        disabled={!data.can.update || runtimes.length < 2}
                        onValueChange={(value) => set({ runtime: value })}
                        options={runtimes.map((item) => ({ value: item.value, label: item.label }))}
                    />
                </Field>
                <Field label="Build mode" error={errors.build_mode}>
                    <Select
                        value={draft.build_mode}
                        disabled={!data.can.update}
                        onValueChange={(value) => set({ build_mode: value })}
                        options={options.build_modes
                            .filter((mode) => runtime.build_modes.includes(mode.value))
                            .map((mode) => ({ value: mode.value, label: mode.label }))}
                    />
                </Field>
                {runtime.is_php ? (
                    <Field label="PHP version" error={errors.php_version}>
                        <Select
                            value={draft.php_version ?? undefined}
                            disabled={!data.can.update}
                            placeholder="Pick a version"
                            onValueChange={(value) => set({ php_version: value })}
                            options={options.php_versions.map((version) => ({ value: version, label: `PHP ${version}` }))}
                        />
                    </Field>
                ) : (
                    !runtime.container && (
                        <Field label="Node version" error={errors.node_version}>
                            <Select
                                value={draft.node_version ?? undefined}
                                disabled={!data.can.update}
                                placeholder="Default"
                                onValueChange={(value) => set({ node_version: value })}
                                options={options.node_versions.map((version) => ({ value: version, label: `Node ${version}` }))}
                            />
                        </Field>
                    )
                )}
                {(runtime.is_php || draft.runtime === 'static') && (
                    <Field label="Web directory" error={errors.web_directory} hint={`Document root: ${settings.document_root}`}>
                        <Input
                            mono
                            value={text('web_directory')}
                            disabled={!data.can.update}
                            onChange={(event) => set({ web_directory: event.target.value })}
                        />
                    </Field>
                )}
                {runtime.proxies && (
                    <Field label="App port" error={errors.app_port} hint="The web process listens here; Caddy proxies to it.">
                        <Input
                            mono
                            inputMode="numeric"
                            value={text('app_port')}
                            disabled={!data.can.update}
                            onChange={(event) => set({ app_port: event.target.value === '' ? null : Number(event.target.value.replace(/\D/g, '')) })}
                        />
                    </Field>
                )}
                {draft.runtime === 'docker' && (
                    <>
                        <Field label="Image" error={errors.docker_image}>
                            <Input
                                mono
                                value={text('docker_image')}
                                disabled={!data.can.update}
                                onChange={(event) => set({ docker_image: event.target.value })}
                            />
                        </Field>
                        <Field label="Dockerfile" error={errors.dockerfile}>
                            <Input
                                mono
                                value={text('dockerfile')}
                                disabled={!data.can.update}
                                onChange={(event) => set({ dockerfile: event.target.value })}
                            />
                        </Field>
                    </>
                )}
                {draft.runtime === 'compose' && (
                    <Field label="Compose file" error={errors.compose_file}>
                        <Input
                            mono
                            value={text('compose_file')}
                            disabled={!data.can.update}
                            onChange={(event) => set({ compose_file: event.target.value })}
                        />
                    </Field>
                )}
                <Field label="App health path" error={errors.health_check_path} hint="Default path of the deploy health check.">
                    <Input
                        mono
                        placeholder="/up"
                        value={text('health_check_path')}
                        disabled={!data.can.update}
                        onChange={(event) => set({ health_check_path: event.target.value })}
                    />
                </Field>
            </form>
        </Section>
    );
}

// ─── Deploy: shared paths ────────────────────────────────────────────────────────────────────────────────────────

/** Files and directories kept across releases (symlinked from shared/). */
export function SharedPathsSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useSiteSettings(ctx);
    const [paths, setPaths] = useState(data?.settings.shared_paths ?? []);
    const { saving, errors, save } = useSave(reload);
    useEffect(() => setPaths(data?.settings.shared_paths ?? []), [data]);

    if (!data) return <Loading error={error} />;
    const dirty = JSON.stringify(paths) !== JSON.stringify(data.settings.shared_paths);
    const update = (index: number, patch: Partial<(typeof paths)[number]>) =>
        setPaths(paths.map((item, i) => (i === index ? { ...item, ...patch } : item)));

    return (
        <Section
            title="Shared paths"
            description="Linked into every release from shared/ so uploads and logs survive deploys."
            footer={
                data.can.update && (
                    <Button
                        variant="primary"
                        loading={saving}
                        disabled={!dirty}
                        onClick={() => void save('PUT', `/sites/${data.site.id}/shared-paths`, { paths }, 'Shared paths saved')}
                    >
                        Save
                    </Button>
                )
            }
        >
            {paths.length === 0 && <p className="text-fg-muted text-sm">No shared paths.</p>}
            {paths.map((path, index) => (
                <div key={index} className="flex items-start gap-2">
                    <div className="grid flex-1 gap-1">
                        <Input
                            mono
                            aria-label="Path"
                            value={path.path}
                            disabled={!data.can.update}
                            onChange={(event) => update(index, { path: event.target.value })}
                        />
                        {errors[`paths.${index}.path`] && <p className="text-danger text-xs">{errors[`paths.${index}.path`]}</p>}
                    </div>
                    <Select
                        className="w-32"
                        aria-label="Type"
                        value={path.type}
                        disabled={!data.can.update}
                        onValueChange={(type) => update(index, { type })}
                        options={[
                            { value: 'directory', label: 'Directory' },
                            { value: 'file', label: 'File' },
                        ]}
                    />
                    {data.can.update && (
                        <Button variant="ghost" aria-label={`Remove ${path.path}`} onClick={() => setPaths(paths.filter((_, i) => i !== index))}>
                            <Trash2 />
                        </Button>
                    )}
                </div>
            ))}
            {data.can.update && (
                <div>
                    <Button size="sm" variant="ghost" onClick={() => setPaths([...paths, { path: '', type: 'directory' }])}>
                        Add path
                    </Button>
                </div>
            )}
        </Section>
    );
}

// ─── Networking: test domain ─────────────────────────────────────────────────────────────────────────────────────

export function TestDomainSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useSiteSettings(ctx);
    const { saving, save } = useSave(reload, ctx.refresh);

    if (!data) return <Loading error={error} />;
    if (!data.options.test_domain) return null;

    return (
        <Section title="Test domain" description="A generated hostname to reach the site before its own domain points here.">
            <Field
                inline
                label={
                    <span>
                        Serve{' '}
                        <code className="font-mono text-xs">{data.settings.test_domain ?? `${data.site.slug}.${data.options.test_domain}`}</code>
                    </span>
                }
            >
                <Switch
                    checked={data.settings.test_domain_enabled}
                    disabled={!data.can.update || saving}
                    onCheckedChange={(on) =>
                        void save('PATCH', `/sites/${data.site.id}`, { test_domain_enabled: on }, on ? 'Test domain enabled' : 'Test domain disabled')
                    }
                />
            </Field>
        </Section>
    );
}

// ─── Servers ─────────────────────────────────────────────────────────────────────────────────────────────────────

/** Targets: which servers the site deploys to, the leader (migrations, scheduler), per-server preparation status. */
export function ServersSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useSiteSettings(ctx);
    const initial = data
        ? { ids: data.targets.map((target) => target.server_id), leader: data.targets.find((target) => target.role === 'leader')?.server_id ?? '' }
        : null;
    const [value, update, reset, dirty] = useDraft(initial);
    const { saving, errors, save } = useSave(reload, ctx.refresh);
    const [retrying, setRetrying] = useState<string | null>(null);

    if (!data || !value) return <Loading error={error} />;

    const toggle = (id: string, on: boolean) => {
        const ids = on ? [...value.ids, id] : value.ids.filter((item) => item !== id);
        update({ ids, leader: ids.includes(value.leader) ? value.leader : (ids[0] ?? '') });
    };

    const retry = async (targetId: string) => {
        setRetrying(targetId);
        try {
            await requestJson(`/sites/${data.site.id}/targets/${targetId}/retry`, 'POST', {});
            toast.success('Preparing the server again');
            await reload();
        } catch (e) {
            toast.error('Could not retry', errorMessage(e));
        } finally {
            setRetrying(null);
        }
    };

    return (
        <Section
            title="Deploy targets"
            description="Where the site deploys. New servers are prepared right away; removed servers keep their files. The leader runs migrations and the scheduler."
            footer={
                data.can.update && (
                    <>
                        <Button variant="ghost" disabled={!dirty || saving} onClick={reset}>
                            Reset
                        </Button>
                        <Button
                            variant="primary"
                            loading={saving}
                            disabled={!dirty || value.ids.length === 0}
                            onClick={() =>
                                void save(
                                    'PUT',
                                    `/sites/${data.site.id}/targets`,
                                    { server_ids: value.ids, leader_server_id: value.leader },
                                    'Servers saved',
                                )
                            }
                        >
                            Save servers
                        </Button>
                    </>
                )
            }
        >
            <ul className="divide-border -my-2 divide-y">
                {data.options.servers.map((server) => {
                    const selected = value.ids.includes(server.id);
                    const target = data.targets.find((item) => item.server_id === server.id);

                    return (
                        <li key={server.id} className="flex flex-wrap items-center gap-3 py-2.5">
                            <Checkbox
                                id={`target-${server.id}`}
                                checked={selected}
                                disabled={!data.can.update}
                                onCheckedChange={(checked) => toggle(server.id, checked === true)}
                            />
                            <label htmlFor={`target-${server.id}`} className="grid min-w-0 flex-1 gap-0.5">
                                <span className="text-fg text-sm font-medium">{server.name}</span>
                                <span className="text-fg-faint font-mono text-[11px]">
                                    {server.type_label}
                                    {server.ipv4 && ` · ${server.ipv4}`}
                                </span>
                            </label>
                            {target && (
                                <span className="flex items-center gap-2">
                                    <StatusBadge
                                        status={
                                            target.status === 'ready'
                                                ? 'active'
                                                : target.status === 'failed'
                                                  ? 'failed'
                                                  : target.status === 'removing'
                                                    ? 'removed'
                                                    : 'provisioning'
                                        }
                                        label={target.status === 'ready' ? 'Ready' : undefined}
                                    />
                                    {target.status === 'failed' && data.can.update && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            icon={<RotateCw />}
                                            loading={retrying === target.id}
                                            onClick={() => void retry(target.id)}
                                        >
                                            Retry
                                        </Button>
                                    )}
                                </span>
                            )}
                            {selected && (
                                <label className="text-fg-muted flex items-center gap-1.5 text-xs">
                                    <input
                                        type="radio"
                                        name={`leader-${data.site.id}`}
                                        className="accent-primary"
                                        disabled={!data.can.update}
                                        checked={value.leader === server.id}
                                        onChange={() => update({ ...value, leader: server.id })}
                                    />
                                    Leader
                                </label>
                            )}
                            {target?.status_message && target.status === 'failed' && (
                                <p className="text-danger w-full pl-7 text-xs">{target.status_message}</p>
                            )}
                        </li>
                    );
                })}
            </ul>
            {Object.values(errors).map((message) => (
                <p key={message} className="text-danger text-xs">
                    {message}
                </p>
            ))}
        </Section>
    );
}

// ─── Laravel ─────────────────────────────────────────────────────────────────────────────────────────────────────

const LARAVEL: { key: 'scheduler' | 'horizon' | 'octane' | 'maintenance'; label: string; hint: string }[] = [
    { key: 'scheduler', label: 'Scheduler', hint: 'Runs schedule:run every minute on the leader.' },
    { key: 'horizon', label: 'Horizon', hint: 'Supervises php artisan horizon on every server.' },
    { key: 'octane', label: 'Octane', hint: 'Serves the app with Octane behind Caddy.' },
    { key: 'maintenance', label: 'Maintenance mode', hint: 'php artisan down on every ready server right away.' },
];

export function LaravelSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useSiteSettings(ctx);
    const { saving, save } = useSave(reload, ctx.refresh);

    if (!data) return <Loading error={error} />;
    const toggles = data.settings.laravel;

    return (
        <Section
            title="Laravel features"
            description="Processes and switches Kiln manages for Laravel apps. Changes apply to the servers right away."
        >
            <ul className="divide-border -my-2 divide-y">
                {LARAVEL.map((item) => (
                    <li key={item.key} className="flex items-center justify-between gap-4 py-2.5">
                        <div className="grid gap-0.5">
                            <span className="text-fg flex items-center gap-2 text-sm font-medium">
                                {item.label}
                                {item.key === 'maintenance' && toggles.maintenance && <Tag tone="warning">down</Tag>}
                            </span>
                            <span className="text-fg-muted text-xs">{item.hint}</span>
                        </div>
                        <Switch
                            aria-label={item.label}
                            checked={toggles[item.key]}
                            disabled={!data.can.update || saving}
                            onCheckedChange={(on) =>
                                void save(
                                    'PUT',
                                    `/sites/${data.site.id}/laravel`,
                                    { ...toggles, [item.key]: on },
                                    `${item.label} ${on ? 'enabled' : 'disabled'}`,
                                )
                            }
                        />
                    </li>
                ))}
            </ul>
        </Section>
    );
}

// ─── Danger ──────────────────────────────────────────────────────────────────────────────────────────────────────

export function DangerSettings({ ctx }: ServiceTabProps) {
    const { data, error } = useSiteSettings(ctx);
    const [open, setOpen] = useState(false);
    const [problem, setProblem] = useState<string | undefined>();

    if (!data) return <Loading error={error} />;
    if (!data.can.delete) return <p className="text-fg-muted text-sm">You can’t delete this site.</p>;

    return (
        <Section title="Delete site" tone="danger">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-fg-muted max-w-lg text-sm">
                    Removes routes, processes, PHP pools and the deploy key. Files under{' '}
                    <code className="font-mono text-xs">{data.settings.root_path}</code> stay on the servers.
                </p>
                <Button variant="danger" icon={<Trash2 />} onClick={() => setOpen(true)}>
                    Delete site
                </Button>
            </div>
            <ConfirmDestructive
                open={open}
                onOpenChange={setOpen}
                title={`Delete ${data.site.name}?`}
                description="The site is removed from every server. This cannot be undone."
                confirmText={data.site.name}
                confirmLabel="Delete site"
                error={problem}
                onConfirm={async (name) => {
                    try {
                        await requestJson(`/sites/${data.site.id}`, 'DELETE', { name });
                        toast.success(`Deleting ${data.site.name}`);
                        setOpen(false);
                        ctx.close();
                        ctx.refresh();
                    } catch (e) {
                        setProblem(errorMessage(e));
                    }
                }}
            />
        </Section>
    );
}
