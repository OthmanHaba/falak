import { Button } from '@/components/kiln/button';
import { Checkbox } from '@/components/kiln/checkbox';
import { CodeBlock } from '@/components/kiln/code-block';
import { Combobox } from '@/components/kiln/combobox';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { Dialog } from '@/components/kiln/dialog';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { KeyValue } from '@/components/kiln/key-value';
import { Section } from '@/components/kiln/section';
import { Select } from '@/components/kiln/select';
import { toast } from '@/components/kiln/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { router, useForm } from '@inertiajs/react';
import { Database, KeyRound, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';

interface Props {
    server: ServerHeader & { timezone: string; ssh_port: number; provider_server_id: string | null; install_command: string | null };
    database: EngineBlock;
    /** Redis / Valkey (only what the server's OS can install). */
    cache: EngineBlock;
    /** Another engine install is running (one at a time per server). */
    busy: boolean;
    can: { update: boolean; delete: boolean; regenerateInstallCommand: boolean };
}

interface EngineBlock {
    engine: string | null;
    installing: boolean;
    allowed: boolean;
    options: { value: string; label: string }[];
}

const NAME_PATTERN = /^[A-Za-z0-9][A-Za-z0-9 ._-]*$/;

function timezones(): string[] {
    try {
        return (Intl as unknown as { supportedValuesOf: (key: string) => string[] }).supportedValuesOf('timeZone');
    } catch {
        return ['UTC'];
    }
}

const SECTIONS = [
    { id: 'general', label: 'General' },
    { id: 'agent', label: 'Agent' },
    { id: 'database', label: 'Database engine' },
    { id: 'cache', label: 'Redis / Valkey' },
    { id: 'danger', label: 'Danger zone' },
];

/** Install an engine on a provisioned server (database or Redis / Valkey): the plan converges with it. */
function EngineSection({
    id,
    title,
    description,
    block,
    serverId,
    canInstall,
    busy,
}: {
    id: string;
    title: string;
    description: string;
    block: EngineBlock;
    serverId: string;
    canInstall: boolean;
    busy: boolean;
}) {
    const [engine, setEngine] = useState(block.options[0]?.value ?? '');
    const [installing, setInstalling] = useState(false);
    const label = (value: string | null) => block.options.find((option) => option.value === value)?.label ?? value;

    const install = () =>
        router.post(
            route('servers.database-engine.store', serverId),
            { engine },
            {
                preserveScroll: true,
                onStart: () => setInstalling(true),
                onFinish: () => setInstalling(false),
                onSuccess: () =>
                    toast.success(`Installing ${label(engine)}`, 'The server applies its provisioning plan; this takes a minute or two.'),
                onError: (errors) => toast.error('Could not install the engine', Object.values(errors)[0]),
            },
        );

    return (
        <Section
            id={id}
            title={title}
            description={description}
            footer={
                canInstall &&
                !block.engine &&
                block.options.length > 0 && (
                    <Button icon={<Database />} onClick={install} loading={installing} disabled={busy}>
                        Install {label(engine) ?? 'engine'}
                    </Button>
                )
            }
        >
            {block.engine ? (
                <p className="text-fg-muted text-sm">
                    {block.installing ? 'Installing ' : 'Runs '}
                    <span className="text-fg font-medium">{label(block.engine)}</span>
                    {block.installing ? '… it appears under Databases once the server reports it.' : '.'}
                </p>
            ) : block.options.length === 0 ? (
                <p className="text-fg-muted text-sm">Nothing this server's OS can install.</p>
            ) : (
                <Field label="Engine">
                    <Select value={engine} onValueChange={setEngine} options={block.options} aria-label={`${title} engine`} disabled={!canInstall} />
                </Field>
            )}
        </Section>
    );
}

export default function Settings({ server, database, cache, busy, can }: Props) {
    const general = useForm({ name: server.name, timezone: server.timezone });
    const [confirmReinstall, setConfirmReinstall] = useState(false);
    const [regenerating, setRegenerating] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [destroyAtProvider, setDestroyAtProvider] = useState(server.provider !== 'custom');
    const [deleteError, setDeleteError] = useState<string | undefined>();
    const zones = useMemo(() => ['UTC', ...timezones().filter((zone) => zone !== 'UTC')].map((zone) => ({ value: zone, label: zone })), []);

    const nameError =
        general.data.name !== '' && !NAME_PATTERN.test(general.data.name) ? 'Use letters, digits, spaces, dots, dashes or underscores.' : null;
    const dirty = general.data.name !== server.name || general.data.timezone !== server.timezone;

    const save: FormEventHandler = (event) => {
        event.preventDefault();
        if (nameError || !general.data.name.trim()) return;
        general.transform((data) => ({
            ...(data.name !== server.name ? { name: data.name } : {}),
            ...(data.timezone !== server.timezone ? { timezone: data.timezone } : {}),
        }));
        general.patch(route('servers.update', server.id), {
            preserveScroll: true,
            onSuccess: () => general.setDefaults(),
        });
    };

    const regenerate = () =>
        router.post(
            route('servers.install-command', server.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => {
                    setRegenerating(false);
                    setConfirmReinstall(false);
                },
                onSuccess: () => toast.success('New install command created', 'Run it on the host to (re)enroll the agent.'),
                onError: (errors) => toast.error('Could not create a command', Object.values(errors)[0]),
            },
        );

    const destroy = (name: string) =>
        new Promise<void>((resolve) => {
            router.delete(route('servers.destroy', server.id), {
                data: { name, destroy_at_provider: destroyAtProvider },
                onError: (errors) => setDeleteError(errors.name ?? Object.values(errors)[0]),
                onSuccess: () => toast.success(`Deleting ${server.name}`),
                onFinish: () => resolve(),
            });
        });

    const isCustom = server.provider === 'custom';

    return (
        <ServerLayout server={server} tab="settings">
            <div className="grid gap-8 md:grid-cols-[160px_minmax(0,1fr)] md:gap-10">
                <nav aria-label="Settings sections" className="hidden md:block">
                    <ul className="sticky top-20 grid gap-0.5">
                        {SECTIONS.filter(
                            (section) =>
                                (section.id !== 'danger' || can.delete) &&
                                (section.id !== 'database' || database.allowed) &&
                                (section.id !== 'cache' || cache.allowed),
                        ).map((section) => (
                            <li key={section.id}>
                                <a
                                    href={`#${section.id}`}
                                    className="text-fg-muted hover:bg-surface-2 hover:text-fg flex h-8 items-center rounded-md px-2 text-sm transition-colors duration-150"
                                >
                                    {section.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="grid max-w-3xl min-w-0 gap-8">
                    <form onSubmit={save}>
                        <Section
                            id="general"
                            title="General"
                            description="The name is shown across Kiln. The timezone is applied to the machine by the provisioning plan."
                            footer={
                                can.update && (
                                    <>
                                        {dirty && (
                                            <Button variant="ghost" onClick={() => general.reset()} disabled={general.processing}>
                                                Discard
                                            </Button>
                                        )}
                                        <Button type="submit" variant="primary" loading={general.processing} disabled={!dirty || Boolean(nameError)}>
                                            Save
                                        </Button>
                                    </>
                                )
                            }
                        >
                            <Field label="Name" required error={general.errors.name ?? nameError}>
                                <Input
                                    value={general.data.name}
                                    onChange={(event) => general.setData('name', event.target.value)}
                                    disabled={!can.update}
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="sm:max-w-sm"
                                />
                            </Field>
                            <Field
                                label="Timezone"
                                error={general.errors.timezone}
                                hint={
                                    server.status === 'active'
                                        ? 'Changing it re-runs provisioning to apply it.'
                                        : 'Applied when the server is provisioned.'
                                }
                            >
                                <Combobox
                                    value={general.data.timezone}
                                    onValueChange={(value) => general.setData('timezone', value ?? 'UTC')}
                                    options={zones}
                                    searchPlaceholder="Search timezones"
                                    disabled={!can.update}
                                    className="sm:max-w-sm"
                                />
                            </Field>
                            <KeyValue
                                columns={3}
                                items={[
                                    { label: 'Server ID', value: server.id, mono: true, copy: server.id },
                                    { label: 'SSH port', value: server.ssh_port },
                                    ...(server.provider_server_id
                                        ? [
                                              {
                                                  label: `${server.provider_label} ID`,
                                                  value: server.provider_server_id,
                                                  mono: true,
                                                  copy: server.provider_server_id,
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </Section>
                    </form>

                    <Section
                        id="agent"
                        title="Agent"
                        description="The Kiln agent keeps this server in sync. A new install command re-binds the server to whichever host runs it and revokes the current agent."
                        footer={
                            can.regenerateInstallCommand &&
                            server.status !== 'deleting' && (
                                <Button
                                    icon={<KeyRound />}
                                    onClick={() => (server.agent ? setConfirmReinstall(true) : regenerate())}
                                    loading={regenerating}
                                >
                                    {server.install_command ? 'Regenerate install command' : 'Create install command'}
                                </Button>
                            )
                        }
                    >
                        {server.install_command ? (
                            <CodeBlock code={server.install_command} title="Install command · run as root on the host" wrap />
                        ) : (
                            <p className="text-fg-muted text-sm">
                                {can.regenerateInstallCommand
                                    ? 'No active install command. Create one to reinstall the agent or move this server to a new host.'
                                    : 'Only members who can manage agents can create install commands.'}
                            </p>
                        )}
                    </Section>

                    {database.allowed && (
                        <EngineSection
                            id="database"
                            title="Database engine"
                            description="Databases for sites on this server run in its engine. It listens on localhost (and to this server's containers), never on the public network."
                            block={database}
                            serverId={server.id}
                            canInstall={can.update}
                            busy={busy || server.status !== 'active'}
                        />
                    )}

                    {cache.allowed && (
                        <EngineSection
                            id="cache"
                            title="Redis / Valkey"
                            description="Each Redis or Valkey service you create runs as its own instance (own port and password) next to the stock one on 6379, which Kiln leaves alone."
                            block={cache}
                            serverId={server.id}
                            canInstall={can.update}
                            busy={busy || server.status !== 'active'}
                        />
                    )}

                    {can.delete && (
                        <Section
                            id="danger"
                            tone="danger"
                            title="Danger zone"
                            description="Deleting revokes the agent and removes the server from Kiln. Services on it stop being managed."
                            footer={
                                <Button variant="danger" icon={<Trash2 />} onClick={() => setDeleting(true)} disabled={server.status === 'deleting'}>
                                    Delete server
                                </Button>
                            }
                        >
                            <p className="text-fg-muted text-sm">
                                {isCustom
                                    ? 'The machine itself is not touched — uninstall the agent on the host if you keep using it.'
                                    : `You can also destroy the machine at ${server.provider_label}.`}
                            </p>
                        </Section>
                    )}
                </div>
            </div>

            <Dialog
                open={confirmReinstall}
                onOpenChange={setConfirmReinstall}
                size="sm"
                title="Create a new install command?"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirmReinstall(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" onClick={regenerate} loading={regenerating}>
                            Create command
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">
                    Running it on a host enrolls that host as {server.name} and revokes the current agent immediately.
                </p>
            </Dialog>

            <ConfirmDestructive
                open={deleting}
                onOpenChange={(open) => {
                    setDeleting(open);
                    if (!open) setDeleteError(undefined);
                }}
                title={`Delete ${server.name}?`}
                description="This cannot be undone. The agent is revoked and the server disappears from Kiln."
                confirmText={server.name}
                confirmLabel="Delete server"
                onConfirm={destroy}
                error={deleteError}
            >
                {!isCustom && (
                    <Field inline label={`Also destroy the machine at ${server.provider_label}`}>
                        <Checkbox checked={destroyAtProvider} onCheckedChange={(checked) => setDestroyAtProvider(checked === true)} />
                    </Field>
                )}
            </ConfirmDestructive>
        </ServerLayout>
    );
}
