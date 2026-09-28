import { domainPayload, DomainPicker, type DomainChoice } from '@/components/domain-picker';
import { Button, Checkbox, Combobox, Field, IconButton, Input, Select, ServiceIcon, Skeleton, Tag, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { createOptionsFor, shellContext, type CreateOption } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type CanvasService, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Command } from 'cmdk';
import { ArrowLeft, Box, ChevronRight, Database, GitBranch, LayoutTemplate, Lock, Rocket, Search, X } from 'lucide-react';
import { Suspense, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { type ProjectAbilities } from '../types';

/** Sites' GET /sites/create JSON (the create form options). */
interface SiteOptions {
    options: {
        frameworks: { value: string; label: string; runtimes: string[]; is_php: boolean }[];
        servers: { id: string; name: string; type_label: string; status: string; docker: boolean }[];
        connections: { id: string; name: string; provider: string; provider_label: string; has_api: boolean }[];
    };
    can_manage_source_control: boolean;
}

interface RepositoryItem {
    full_name: string;
    default_branch: string;
    private: boolean;
}

/** Databases' GET /databases JSON (engine servers). */
interface EngineServer {
    id: string;
    server_id: string;
    server_name: string;
    engine: string;
    engine_label: string;
    version: string | null;
}

type BuiltinStep = 'root' | 'git' | 'docker' | 'empty' | 'database';
/** Built-in kinds, or `option:<id>` for a kind another module registered (registerCreateOptions). */
type Step = BuiltinStep | `option:${string}`;

export interface CreatePickerProps {
    projectId: string;
    environmentSlug: string;
    can: ProjectAbilities;
    /** Canvas coordinates for the new card (right-click position); auto-placed when null. */
    position: { x: number; y: number } | null;
    /** Screen position to open at (right-click); top-right of the canvas when null. */
    anchor: { x: number; y: number } | null;
    onClose: () => void;
    /** The new card, and the first deployment when one was started. */
    onCreated: (service: CanvasService, deploymentId: string | null) => void;
    /** Open a registered option right away (e.g. ⌘K → Deploy template…). */
    initialOption?: string | null;
}

/** Guess the framework preset from the repository name (the user can change it before deploying). */
export function detectPreset(repository: string, frameworks: string[]): string {
    const name = repository.toLowerCase();
    const rules: [RegExp, string][] = [
        [/next/, 'next'],
        [/nuxt/, 'nuxt'],
        [/wordpress|(^|[/-])wp([-.]|$)/, 'wordpress'],
        [/statamic/, 'statamic'],
        [/symfony/, 'symfony'],
        [/docs|static|landing|hugo|astro|jekyll/, 'static'],
        [/docker|compose/, 'docker'],
        [/bun|node|express|worker|bot/, 'node'],
    ];
    const hit = rules.find(([pattern, preset]) => pattern.test(name) && frameworks.includes(preset));

    return hit?.[1] ?? (frameworks.includes('laravel') ? 'laravel' : (frameworks[0] ?? 'laravel'));
}

const ENGINES = [
    { value: 'postgresql', label: 'PostgreSQL' },
    { value: 'mysql', label: 'MySQL' },
    { value: 'mariadb', label: 'MariaDB' },
    { value: 'redis', label: 'Redis', unsupported: true },
] as const;

function slugName(value: string): string {
    return (
        (value.split('/').pop() ?? value)
            .replace(/\.git$/, '')
            .replace(/[^A-Za-z0-9 ._-]+/g, '-')
            .replace(/^[^A-Za-z0-9]+/, '') || 'service'
    );
}

function Errors({ errors }: { errors: string[] }) {
    if (errors.length === 0) return null;

    return (
        <div role="alert" className="bg-danger-soft text-danger grid gap-0.5 rounded-md px-3 py-2 text-xs">
            {errors.map((message) => (
                <span key={message}>{message}</span>
            ))}
        </div>
    );
}

function ServersField({
    servers,
    value,
    onChange,
    error,
    requireDocker = false,
}: {
    servers: SiteOptions['options']['servers'];
    value: string[];
    onChange: (ids: string[]) => void;
    error?: string;
    requireDocker?: boolean;
}) {
    const eligible = servers.filter((server) => !requireDocker || server.docker);

    return (
        <Field
            label="Servers"
            hint={value.length > 1 ? 'The first server is the leader (runs migrations and the scheduler).' : undefined}
            error={error}
        >
            {eligible.length === 0 ? (
                <p className="text-fg-muted text-xs">
                    No {requireDocker ? 'Docker-capable ' : ''}app servers yet.{' '}
                    <Link href="/servers/create" className="text-primary hover:underline">
                        Add a server
                    </Link>
                </p>
            ) : (
                <div className="border-border grid max-h-36 overflow-y-auto rounded-md border">
                    {eligible.map((server) => {
                        const checked = value.includes(server.id);

                        return (
                            <label key={server.id} className="hover:bg-surface-2 flex cursor-pointer items-center gap-2.5 px-2.5 py-1.5 text-sm">
                                <Checkbox
                                    checked={checked}
                                    onCheckedChange={(next) => onChange(next ? [...value, server.id] : value.filter((id) => id !== server.id))}
                                    aria-label={server.name}
                                />
                                <span className="text-fg font-mono text-xs">{server.name}</span>
                                <span className="text-fg-faint text-xs">{server.type_label}</span>
                                {checked && value[0] === server.id && value.length > 1 && <Tag className="ml-auto">leader</Tag>}
                                {server.status !== 'active' && (
                                    <Tag tone="warning" className="ml-auto">
                                        {server.status}
                                    </Tag>
                                )}
                            </label>
                        );
                    })}
                </div>
            )}
        </Field>
    );
}

/** The site slug Sites derives from the service name (lowercase, dashes). */
function siteSlug(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50);
}

/** Generated sslip.io name, the test domain or the user's own (with DNS instructions); needs the servers first. */
function DomainField({
    name,
    serverIds,
    value,
    onChange,
    error,
}: {
    name: string;
    serverIds: string[];
    value: DomainChoice | null;
    onChange: (value: DomainChoice) => void;
    error?: string;
}) {
    if (serverIds.length === 0) return null;

    return (
        <Field label="Domain" error={error}>
            <DomainPicker label={siteSlug(name) || 'app'} serverIds={serverIds} value={value} onChange={onChange} ariaLabel="Domain" />
        </Field>
    );
}

/**
 * The canvas Create picker (§4). Everything happens inline: pick a kind, fill the few fields it needs, and the
 * service is created through the Projects create-service endpoint; sites deploy right away and open on their
 * Deploy view.
 */
export function CreatePicker({ projectId, environmentSlug, can, position, anchor, onClose, onCreated, initialOption = null }: CreatePickerProps) {
    const { props } = usePage<SharedData>();
    const options = useMemo(() => (can.create_sites ? createOptionsFor(shellContext(props)) : []), [can.create_sites, props]);
    const [step, setStep] = useState<Step>(() =>
        initialOption && options.some((option) => option.id === initialOption) ? `option:${initialOption}` : 'root',
    );
    const option = step.startsWith('option:') ? (options.find((item) => `option:${item.id}` === step) ?? null) : null;
    const ref = useRef<HTMLDivElement>(null);
    const needsSites = step === 'git' || step === 'docker' || step === 'empty';
    const sites = useJson<SiteOptions>(needsSites && can.create_sites ? '/sites/create' : null);
    const engines = useJson<EngineServer[]>(step === 'database' && can.create_databases ? '/databases' : null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Esc / click outside closes (clicks inside Radix popovers opened from the picker don't count).
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !document.querySelector('[data-radix-popper-content-wrapper]')) onClose();
        };
        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as HTMLElement | null;
            if (!target || ref.current?.contains(target) || target.closest('[data-radix-popper-content-wrapper]')) return;
            onClose();
        };
        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('pointerdown', onPointerDown);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('pointerdown', onPointerDown);
        };
    }, [onClose]);

    const go = (next: Step) => {
        setErrors({});
        setStep(next);
    };

    /** POST the service, then (sites with a source) start the first deployment. */
    const create = async (payload: Record<string, unknown>, deploy: boolean) => {
        setSubmitting(true);
        setErrors({});
        try {
            const created = await requestJson<{ data: CanvasService; warnings: string[] }>(
                `/projects/${projectId}/${environmentSlug}/services`,
                'POST',
                {
                    ...payload,
                    ...(position ?? {}),
                },
            );
            created.warnings.forEach((warning) => toast.warning(warning));
            let deploymentId: string | null = null;

            if (deploy && created.data.kind === 'site') {
                try {
                    const deployment = await requestJson<{ data: { id: string } }>(`/sites/${created.data.ref_id}/deployments`, 'POST', {});
                    deploymentId = deployment.data.id;
                } catch (error) {
                    toast.error(`${created.data.name} was created, but the first deploy did not start`, errorMessage(error));
                }
            }

            toast.success(`${created.data.name} created`);
            onCreated(created.data, deploymentId);
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { form: errorMessage(error) });
        } finally {
            setSubmitting(false);
        }
    };

    const wide = option?.wide ?? false;
    const style = anchor
        ? {
              left: Math.max(8, Math.min(anchor.x, window.innerWidth - (wide ? 568 : 408))),
              top: Math.max(56, Math.min(anchor.y, window.innerHeight - (wide ? 640 : 480))),
          }
        : undefined;

    const titles: Record<BuiltinStep, string> = {
        root: 'Create',
        git: 'Deploy a Git repository',
        docker: 'Deploy a Docker image',
        empty: 'Empty service',
        database: 'Add a database',
    };

    return (
        <div
            ref={ref}
            role="dialog"
            aria-label={option ? (option.stepTitle ?? option.title) : titles[step as BuiltinStep]}
            style={style}
            className={cn(
                'animate-fade-in border-border bg-surface-1 shadow-panel z-30 flex flex-col overflow-hidden rounded-xl border',
                wide
                    ? 'max-h-[min(760px,calc(100svh-7rem))] w-[min(560px,calc(100vw-2rem))]'
                    : 'max-h-[min(640px,calc(100svh-7rem))] w-[min(400px,calc(100vw-2rem))]',
                anchor ? 'fixed' : 'absolute top-14 right-3 md:right-4',
            )}
        >
            <div className="border-border flex items-center gap-1 border-b px-2 py-2">
                {step !== 'root' && <IconButton size="sm" label="Back" icon={<ArrowLeft />} onClick={() => go('root')} />}
                <span className="text-fg flex-1 px-1 text-sm font-medium">
                    {option ? (option.stepTitle ?? option.title) : titles[step as BuiltinStep]}
                </span>
                <IconButton size="sm" label="Close" shortcut="Esc" icon={<X />} onClick={onClose} />
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {step === 'root' && <RootStep can={can} options={options} onPick={go} />}
                {option && (
                    <Suspense
                        fallback={
                            <div className="grid gap-2 p-4">
                                {[0, 1, 2].map((i) => (
                                    <Skeleton key={i} className="h-8" />
                                ))}
                            </div>
                        }
                    >
                        <option.component
                            projectId={projectId}
                            environmentSlug={environmentSlug}
                            position={position}
                            onCreated={onCreated}
                            onClose={onClose}
                        />
                    </Suspense>
                )}
                {needsSites && !sites.data && (
                    <div className="grid gap-2 p-4">
                        {sites.error ? <Errors errors={[sites.error]} /> : [0, 1, 2].map((i) => <Skeleton key={i} className="h-8" />)}
                    </div>
                )}
                {step === 'git' && sites.data && (
                    <GitStep options={sites.data} submitting={submitting} errors={errors} onSubmit={(payload) => void create(payload, true)} />
                )}
                {step === 'docker' && sites.data && (
                    <DockerStep options={sites.data} submitting={submitting} errors={errors} onSubmit={(payload) => void create(payload, true)} />
                )}
                {step === 'empty' && sites.data && (
                    <EmptyStep options={sites.data} submitting={submitting} errors={errors} onSubmit={(payload) => void create(payload, false)} />
                )}
                {step === 'database' && (
                    <DatabaseStep
                        servers={engines.data}
                        loadError={engines.error}
                        submitting={submitting}
                        errors={errors}
                        onSubmit={(payload) => void create(payload, false)}
                    />
                )}
            </div>
        </div>
    );
}

function RootStep({ can, options, onPick }: { can: ProjectAbilities; options: CreateOption[]; onPick: (step: Step) => void }) {
    const items: { id: Step | 'template'; title: string; description: string; icon: ReactNode; disabled?: boolean; soon?: boolean }[] = [
        {
            id: 'git',
            title: 'Git repository',
            description: 'Deploy a repo from GitHub, GitLab, Bitbucket or any git server',
            icon: <GitBranch />,
            disabled: !can.create_sites,
        },
        {
            id: 'database',
            title: 'Database',
            description: 'PostgreSQL, MySQL or MariaDB on one of your servers',
            icon: <Database />,
            disabled: !can.create_databases,
        },
        {
            id: 'docker',
            title: 'Docker image',
            description: 'Run a public or private registry image',
            icon: <ServiceIcon name="docker" size={16} mono />,
            disabled: !can.create_sites,
        },
        { id: 'empty', title: 'Empty service', description: 'Configure the source later', icon: <Box />, disabled: !can.create_sites },
        ...options.map((option) => ({
            id: `option:${option.id}` as Step,
            title: option.title,
            description: option.description,
            icon: <option.icon />,
        })),
        // Stand-in until the Templates module registers its option.
        ...(options.some((option) => option.id === 'template')
            ? []
            : [
                  {
                      id: 'template' as const,
                      title: 'Template',
                      description: 'Multi-service compose templates',
                      icon: <LayoutTemplate />,
                      disabled: true,
                      soon: true,
                  },
              ]),
    ];

    return (
        <Command loop className="flex flex-col" label="Create a service">
            <div className="border-border flex items-center gap-2 border-b px-3">
                <Search className="text-fg-faint size-4" aria-hidden />
                <Command.Input
                    autoFocus
                    placeholder="What do you want to create?"
                    className="text-fg placeholder:text-fg-faint h-10 w-full bg-transparent text-sm outline-none"
                />
            </div>
            <Command.List className="p-1.5">
                <Command.Empty className="text-fg-faint px-3 py-6 text-center text-sm">Nothing matches.</Command.Empty>
                {items.map((item) => (
                    <Command.Item
                        key={item.id}
                        value={`${item.title} ${item.description}`}
                        disabled={item.disabled}
                        onSelect={() => item.id !== 'template' && onPick(item.id)}
                        className="data-[selected=true]:bg-surface-2 flex cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 data-[disabled=true]:cursor-default data-[disabled=true]:opacity-50"
                    >
                        <span className="border-border bg-surface-2 text-fg-muted flex size-8 shrink-0 items-center justify-center rounded-md border [&_svg]:size-4">
                            {item.icon}
                        </span>
                        <span className="grid min-w-0 flex-1">
                            <span className="text-fg text-sm font-medium">{item.title}</span>
                            <span className="text-fg-faint truncate text-xs">{item.description}</span>
                        </span>
                        {item.soon ? <Tag>Coming soon</Tag> : <ChevronRight className="text-fg-faint size-4" aria-hidden />}
                    </Command.Item>
                ))}
            </Command.List>
        </Command>
    );
}

interface StepProps {
    options: SiteOptions;
    submitting: boolean;
    errors: Record<string, string>;
    onSubmit: (payload: Record<string, unknown>) => void;
}

function otherErrors(errors: Record<string, string>, shown: string[]): string[] {
    return Object.entries(errors)
        .filter(([key]) => !shown.includes(key.split('.')[0]))
        .map(([, message]) => message);
}

function GitStep({ options, submitting, errors, onSubmit }: StepProps) {
    const { connections, frameworks, servers } = options.options;
    const [connectionId, setConnectionId] = useState<string>(connections[0]?.id ?? '');
    const connection = connections.find((item) => item.id === connectionId) ?? null;
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<RepositoryItem[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [repository, setRepository] = useState('');
    const [branches, setBranches] = useState<string[]>([]);
    const [branch, setBranch] = useState('');
    const [framework, setFramework] = useState('laravel');
    const [serverIds, setServerIds] = useState<string[]>(
        servers
            .filter((server) => server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id),
    );
    const [name, setName] = useState('');
    const [domain, setDomain] = useState<DomainChoice | null>(null);
    const frameworkValues = useMemo(() => frameworks.map((item) => item.value), [frameworks]);

    useEffect(() => {
        if (!connection?.has_api) {
            setRepositories(null);

            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            requestJson<{ data: RepositoryItem[] }>(
                `/source-control/connections/${connection.id}/repositories?search=${encodeURIComponent(search)}`,
                'GET',
                undefined,
                {
                    signal: controller.signal,
                },
            )
                .then((body) => {
                    setRepositories(body.data);
                    setLoadError(null);
                })
                .catch((error: unknown) => !controller.signal.aborted && setLoadError(errorMessage(error, 'Could not load repositories')));
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [connection, search]);

    useEffect(() => {
        if (!connection?.has_api || !repository) {
            setBranches([]);

            return;
        }
        let cancelled = false;
        requestJson<{ data: { name: string }[] }>(
            `/source-control/connections/${connection.id}/branches?repository=${encodeURIComponent(repository)}`,
        )
            .then((body) => !cancelled && setBranches(body.data.map((item) => item.name)))
            .catch(() => !cancelled && setBranches([]));

        return () => {
            cancelled = true;
        };
    }, [connection, repository]);

    const pick = (item: { full_name: string; default_branch: string }) => {
        setRepository(item.full_name);
        setBranch(item.default_branch);
        setFramework(detectPreset(item.full_name, frameworkValues));
        setName(slugName(item.full_name));
    };

    if (connections.length === 0) {
        return (
            <div className="grid gap-3 p-4 text-sm">
                <p className="text-fg-muted">
                    Connect GitHub to deploy its repositories: Kiln installs a GitHub App and you pick the repositories on GitHub.
                </p>
                {options.can_manage_source_control ? (
                    <div className="flex flex-wrap gap-2">
                        {/* Full page navigation: the settings page continues to github.com and GitHub returns here. */}
                        <Button asChild variant="primary" className="w-fit">
                            <a href={`/settings/source-control?connect=github&return_to=${encodeURIComponent(window.location.pathname)}`}>
                                Connect GitHub
                            </a>
                        </Button>
                        <Button asChild variant="ghost" className="w-fit">
                            <Link href="/settings/source-control">GitLab, Bitbucket or git server</Link>
                        </Button>
                    </div>
                ) : (
                    <p className="text-fg-faint text-xs">Ask an organization admin to connect one.</p>
                )}
            </div>
        );
    }

    const detected = frameworks.find((item) => item.value === framework);

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    kind: 'site',
                    name,
                    framework,
                    source_connection_id: connectionId,
                    repository,
                    branch,
                    push_to_deploy: true,
                    server_ids: serverIds,
                    leader_server_id: serverIds[0],
                    domain: domainPayload(domain),
                });
            }}
        >
            <Field label="Connection">
                <Select
                    value={connectionId}
                    onValueChange={(value) => {
                        setConnectionId(value);
                        setRepository('');
                        setBranch('');
                    }}
                    options={connections.map((item) => ({ value: item.id, label: `${item.name} · ${item.provider_label}` }))}
                />
            </Field>

            {!repository ? (
                connection?.has_api ? (
                    <div className="grid gap-1.5">
                        <span className="text-fg text-xs font-medium">Repository</span>
                        <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search repositories" autoFocus />
                        <div className="border-border max-h-52 overflow-y-auto rounded-md border" role="listbox" aria-label="Repositories">
                            {loadError && <p className="text-danger p-3 text-xs">{loadError}</p>}
                            {!loadError && repositories === null && <Skeleton className="m-2 h-6" />}
                            {repositories?.length === 0 && <p className="text-fg-faint p-3 text-xs">No repositories match.</p>}
                            {repositories?.map((item) => (
                                <button
                                    key={item.full_name}
                                    type="button"
                                    role="option"
                                    aria-selected={false}
                                    onClick={() => pick(item)}
                                    className="hover:bg-surface-2 focus-visible:bg-surface-2 flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-sm outline-none"
                                >
                                    <GitBranch className="text-fg-faint size-3.5 shrink-0" aria-hidden />
                                    <span className="text-fg min-w-0 flex-1 truncate">{item.full_name}</span>
                                    {item.private && <Lock className="text-fg-faint size-3" aria-label="private" />}
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <CustomRepository onPick={pick} />
                )
            ) : (
                <>
                    <div className="border-border bg-surface-2 flex items-center gap-2 rounded-md border px-2.5 py-2">
                        <GitBranch className="text-fg-muted size-4 shrink-0" aria-hidden />
                        <span className="text-fg min-w-0 flex-1 truncate font-mono text-xs">{repository}</span>
                        <Button size="sm" variant="ghost" onClick={() => setRepository('')}>
                            Change
                        </Button>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Branch" error={errors.branch}>
                            {branches.length > 0 ? (
                                <Combobox
                                    value={branch}
                                    onValueChange={(value) => setBranch(value ?? '')}
                                    options={branches.map((item) => ({ value: item, label: item }))}
                                />
                            ) : (
                                <Input value={branch} onChange={(event) => setBranch(event.target.value)} mono />
                            )}
                        </Field>
                        <Field label="Preset" hint={detected ? 'Detected from the repository' : undefined} error={errors.framework}>
                            <Select
                                value={framework}
                                onValueChange={setFramework}
                                options={frameworks.map((item) => ({ value: item.value, label: item.label }))}
                            />
                        </Field>
                    </div>
                    <Field label="Service name" error={errors.name}>
                        <Input value={name} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <ServersField servers={servers} value={serverIds} onChange={setServerIds} error={errors.server_ids} />
                    <DomainField name={name} serverIds={serverIds} value={domain} onChange={setDomain} error={errors.domain} />
                    <Errors errors={otherErrors(errors, ['branch', 'framework', 'name', 'server_ids', 'domain'])} />
                    <Button
                        variant="primary"
                        type="submit"
                        icon={<Rocket />}
                        loading={submitting}
                        disabled={!branch || serverIds.length === 0 || !name}
                    >
                        Deploy
                    </Button>
                </>
            )}
        </form>
    );
}

function CustomRepository({ onPick }: { onPick: (item: { full_name: string; default_branch: string }) => void }) {
    const [url, setUrl] = useState('');

    return (
        <div className="grid gap-2">
            <Field label="Clone URL" hint="SSH or HTTPS; Kiln adds a deploy key you install on the git server.">
                <Input value={url} onChange={(event) => setUrl(event.target.value)} placeholder="git@git.example.com:acme/shop.git" mono autoFocus />
            </Field>
            <Button className="w-fit" disabled={!url} onClick={() => onPick({ full_name: url.trim(), default_branch: 'main' })}>
                Continue
            </Button>
        </div>
    );
}

function DockerStep({ options, submitting, errors, onSubmit }: StepProps) {
    const [image, setImage] = useState('');
    const [port, setPort] = useState('');
    const [name, setName] = useState('');
    const [domain, setDomain] = useState<DomainChoice | null>(null);
    const [serverIds, setServerIds] = useState<string[]>(() =>
        options.options.servers
            .filter((server) => server.docker && server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id),
    );

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    kind: 'site',
                    name: name || slugName(image.split(':')[0]),
                    framework: 'docker',
                    runtime: 'docker',
                    docker_image: image,
                    app_port: port ? Number(port) : null,
                    server_ids: serverIds,
                    leader_server_id: serverIds[0],
                    domain: domainPayload(domain),
                });
            }}
        >
            <Field label="Image" hint="e.g. ghcr.io/acme/api:latest" error={errors.docker_image}>
                <Input value={image} onChange={(event) => setImage(event.target.value)} placeholder="nginx:1.27" mono autoFocus />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Service name" error={errors.name}>
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder={image ? slugName(image.split(':')[0]) : 'api'}
                    />
                </Field>
                <Field label="Port" hint="Container port" error={errors.app_port}>
                    <Input value={port} onChange={(event) => setPort(event.target.value.replace(/\D/g, ''))} placeholder="auto" inputMode="numeric" />
                </Field>
            </div>
            <ServersField servers={options.options.servers} value={serverIds} onChange={setServerIds} error={errors.server_ids} requireDocker />
            <DomainField
                name={name || slugName(image.split(':')[0])}
                serverIds={serverIds}
                value={domain}
                onChange={setDomain}
                error={errors.domain}
            />
            <Errors errors={otherErrors(errors, ['docker_image', 'name', 'app_port', 'server_ids', 'domain'])} />
            <Button variant="primary" type="submit" icon={<Rocket />} loading={submitting} disabled={!image || serverIds.length === 0}>
                Deploy
            </Button>
        </form>
    );
}

function EmptyStep({ options, submitting, errors, onSubmit }: StepProps) {
    const [name, setName] = useState('');
    const [framework, setFramework] = useState('laravel');
    const [serverIds, setServerIds] = useState<string[]>(
        options.options.servers
            .filter((server) => server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id),
    );

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ kind: 'site', name, framework, server_ids: serverIds, leader_server_id: serverIds[0] });
            }}
        >
            <p className="text-fg-muted text-xs">
                Creates the service on its servers without a source; connect a repository or image later in Settings.
            </p>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Service name" error={errors.name}>
                    <Input value={name} onChange={(event) => setName(event.target.value)} placeholder="api" autoFocus />
                </Field>
                <Field label="Preset" error={errors.framework}>
                    <Select
                        value={framework}
                        onValueChange={setFramework}
                        options={options.options.frameworks.map((item) => ({ value: item.value, label: item.label }))}
                    />
                </Field>
            </div>
            <ServersField servers={options.options.servers} value={serverIds} onChange={setServerIds} error={errors.server_ids} />
            <Errors errors={otherErrors(errors, ['name', 'framework', 'server_ids'])} />
            <Button variant="primary" type="submit" loading={submitting} disabled={!name || serverIds.length === 0}>
                Create service
            </Button>
        </form>
    );
}

function DatabaseStep({
    servers,
    loadError,
    submitting,
    errors,
    onSubmit,
}: {
    servers: EngineServer[] | null;
    loadError: string | null;
    submitting: boolean;
    errors: Record<string, string>;
    onSubmit: (payload: Record<string, unknown>) => void;
}) {
    const [engine, setEngine] = useState<string | null>(null);
    const [picked, setServerId] = useState('');
    const [name, setName] = useState('app');
    const candidates = (servers ?? []).filter((server) => server.engine === engine);
    const serverId = candidates.some((server) => server.server_id === picked) ? picked : (candidates[0]?.server_id ?? '');

    return (
        <form
            className="grid gap-4 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ kind: 'database', engine, server_id: serverId, name });
            }}
        >
            <div className="grid gap-1.5">
                <span className="text-fg text-xs font-medium">Engine</span>
                <div className="grid grid-cols-2 gap-2">
                    {ENGINES.map((item) => {
                        const available = (servers ?? []).some((server) => server.engine === item.value);
                        const unsupported = 'unsupported' in item;

                        return (
                            <button
                                key={item.value}
                                type="button"
                                disabled={unsupported}
                                aria-pressed={engine === item.value}
                                onClick={() => setEngine(item.value)}
                                className={cn(
                                    'border-border hover:border-border-strong flex items-center gap-2 rounded-md border px-2.5 py-2 text-left transition-colors duration-150 disabled:pointer-events-none disabled:opacity-50',
                                    engine === item.value && 'border-primary bg-primary-soft',
                                )}
                            >
                                <ServiceIcon name={item.value} size={16} />
                                <span className="grid min-w-0">
                                    <span className="text-fg text-sm">{item.label}</span>
                                    <span className="text-fg-faint text-2xs">
                                        {unsupported ? 'Coming soon' : servers === null ? '…' : available ? 'Installed' : 'Not installed'}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
            {loadError && <Errors errors={[loadError]} />}
            {engine && (
                <>
                    <Field label="Server" error={errors.server_id}>
                        {candidates.length > 0 ? (
                            <Select
                                value={serverId}
                                onValueChange={setServerId}
                                options={candidates.map((server) => ({
                                    value: server.server_id,
                                    label: `${server.server_name} · ${server.engine_label}${server.version ? ` ${server.version}` : ''}`,
                                }))}
                            />
                        ) : (
                            <p className="text-fg-muted text-xs">
                                None of your servers runs {ENGINES.find((item) => item.value === engine)?.label}.{' '}
                                <Link href="/servers/create" className="text-primary hover:underline">
                                    Provision a database server
                                </Link>
                            </p>
                        )}
                    </Field>
                    <Field label="Database name" hint="A user with the same name and a generated password is created too." error={errors.name}>
                        <Input value={name} onChange={(event) => setName(event.target.value)} mono />
                    </Field>
                    <Errors errors={otherErrors(errors, ['server_id', 'name'])} />
                    <Button variant="primary" type="submit" loading={submitting} disabled={!serverId || !name}>
                        Create database
                    </Button>
                </>
            )}
        </form>
    );
}
