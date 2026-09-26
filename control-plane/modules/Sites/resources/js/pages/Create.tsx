import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Check, Loader2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';
import { RepositoryPicker } from '../components/repository-picker';
import { formatBytes } from '../components/site-ui';
import { type ServerOption, type SiteOptions } from '../types';

interface Props {
    options: SiteOptions;
    canManageSourceControl: boolean;
}

type CreateForm = {
    name: string;
    slug: string;
    framework: string;
    runtime: string;
    build_mode: string;
    php_version: string;
    node_version: string;
    server_ids: string[];
    leader_server_id: string;
    isolated: boolean;
    web_directory: string;
    app_port: string;
    docker_image: string;
    dockerfile: string;
    compose_file: string;
    source_connection_id: string;
    repository: string;
    branch: string;
    push_to_deploy: boolean;
    test_domain_enabled: boolean;
};

const STEPS = ['Basics', 'Servers', 'Runtime', 'Repository', 'Review'] as const;
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sites', href: '/sites' },
    { title: 'Create', href: '/sites/create' },
];

// Which step shows the error for a field.
const FIELD_STEP: Record<string, number> = {
    name: 0,
    slug: 0,
    framework: 0,
    server_ids: 1,
    leader_server_id: 1,
    runtime: 2,
    build_mode: 2,
    php_version: 2,
    node_version: 2,
    app_port: 2,
    web_directory: 2,
    docker_image: 2,
    dockerfile: 2,
    compose_file: 2,
    source_connection_id: 3,
    repository: 3,
    branch: 3,
};

function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50);
}

export default function Create({ options, canManageSourceControl }: Props) {
    const [step, setStep] = useState(0);
    const firstFramework = options.frameworks[0];
    const form = useForm<CreateForm>({
        name: '',
        slug: '',
        framework: firstFramework?.value ?? 'laravel',
        runtime: firstFramework?.runtimes[0] ?? 'frankenphp',
        build_mode: 'native',
        php_version: '',
        node_version: options.node_versions.includes('22') ? '22' : (options.node_versions[0] ?? ''),
        server_ids: [],
        leader_server_id: '',
        isolated: false,
        web_directory: firstFramework?.web_directory ?? 'public',
        app_port: '',
        docker_image: '',
        dockerfile: '',
        compose_file: '',
        source_connection_id: '',
        repository: '',
        branch: '',
        push_to_deploy: true,
        test_domain_enabled: true,
    });
    const { data, setData, errors, processing } = form;

    const framework = options.frameworks.find((item) => item.value === data.framework) ?? firstFramework;
    const runtime = options.runtimes.find((item) => item.value === data.runtime) ?? options.runtimes[0];
    const selectedServers = options.servers.filter((server) => data.server_ids.includes(server.id));

    // PHP versions installed on every selected server.
    const phpVersions = useMemo(() => {
        if (selectedServers.length === 0) {
            return options.php_versions;
        }

        return options.php_versions.filter((version) => selectedServers.every((server) => server.php_versions.includes(version)));
    }, [options.php_versions, selectedServers]);

    const serverProblem = (server: ServerOption): string | null => {
        if (runtime.is_php && !server.php_runtime) return 'No PHP';
        if (data.runtime === 'frankenphp' && server.php_runtime !== 'frankenphp') return 'No FrankenPHP';
        if (runtime.container && !server.docker) return 'No Docker';
        if (data.build_mode === 'on-server' && (server.memory_bytes ?? 0) < options.on_server_min_memory_bytes) return '< 2 GB RAM';

        return null;
    };

    const chooseFramework = (value: string) => {
        const next = options.frameworks.find((item) => item.value === value);

        if (!next) return;

        const nextRuntime = next.runtimes[0];
        const runtimeOption = options.runtimes.find((item) => item.value === nextRuntime);
        setData((current) => ({
            ...current,
            framework: value,
            runtime: nextRuntime,
            build_mode: runtimeOption?.build_modes[0] ?? 'native',
            web_directory: next.web_directory,
        }));
    };

    const chooseRuntime = (value: string) => {
        const option = options.runtimes.find((item) => item.value === value);
        setData((current) => ({
            ...current,
            runtime: value,
            build_mode: option && option.build_modes.includes(current.build_mode) ? current.build_mode : (option?.build_modes[0] ?? 'native'),
        }));
    };

    const toggleServer = (id: string, checked: boolean) => {
        setData((current) => {
            const server_ids = checked ? [...current.server_ids, id] : current.server_ids.filter((item) => item !== id);
            const leader_server_id = server_ids.includes(current.leader_server_id) ? current.leader_server_id : (server_ids[0] ?? '');

            return { ...current, server_ids, leader_server_id };
        });
    };

    const canContinue = [
        data.name.trim() !== '',
        data.server_ids.length > 0,
        !runtime.is_php || data.php_version !== '',
        !data.source_connection_id || (data.repository !== '' && data.branch !== ''),
        true,
    ];

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (step < STEPS.length - 1) {
            if (step === 1 && runtime.is_php && !data.php_version) {
                setData('php_version', phpVersions[phpVersions.length - 1] ?? '');
            }

            setStep(step + 1);

            return;
        }

        form.transform((current) => ({
            ...current,
            slug: current.slug || undefined,
            php_version: runtime.is_php ? current.php_version : null,
            app_port: runtime.proxies && current.app_port ? Number(current.app_port) : null,
            docker_image: current.docker_image || null,
            dockerfile: current.dockerfile || null,
            compose_file: current.compose_file || null,
            source_connection_id: current.source_connection_id || null,
            repository: current.source_connection_id ? current.repository : null,
            branch: current.source_connection_id ? current.branch : null,
        }));

        form.post('/sites', {
            onError: (errs) => {
                const steps = Object.keys(errs).map((field) => FIELD_STEP[field.split('.')[0]] ?? 4);
                setStep(Math.min(...steps));
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create site" />
            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6 p-4">
                <Heading title="Create site" description="Deploy an application to one or more servers" />

                <ol className="flex flex-wrap gap-2 text-sm" aria-label="Steps">
                    {STEPS.map((label, index) => (
                        <li key={label}>
                            <button
                                type="button"
                                onClick={() => index < step && setStep(index)}
                                className={cn(
                                    'flex items-center gap-2 rounded-full border px-3 py-1',
                                    index === step && 'border-primary bg-primary text-primary-foreground',
                                    index < step && 'hover:bg-muted',
                                    index > step && 'text-muted-foreground cursor-default',
                                )}
                                aria-current={index === step ? 'step' : undefined}
                            >
                                {index < step ? <Check className="size-3.5" /> : <span className="text-xs">{index + 1}</span>}
                                {label}
                            </button>
                        </li>
                    ))}
                </ol>

                {step === 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Basics</CardTitle>
                            <CardDescription>
                                The framework preset fills in the runtime, web directory, deploy script and shared paths.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        autoFocus
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Shop"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="slug">Directory / slug</Label>
                                    <Input
                                        id="slug"
                                        value={data.slug}
                                        onChange={(e) => setData('slug', e.target.value)}
                                        placeholder={slugify(data.name) || 'shop'}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        /srv/kiln/sites/{data.slug || slugify(data.name) || 'shop'}
                                        {options.test_domain && ` · ${data.slug || slugify(data.name) || 'shop'}.${options.test_domain}`}
                                    </p>
                                    <InputError message={errors.slug} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label>Framework</Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                    {options.frameworks.map((item) => (
                                        <button
                                            type="button"
                                            key={item.value}
                                            onClick={() => chooseFramework(item.value)}
                                            className={cn(
                                                'rounded-lg border px-3 py-2 text-left text-sm transition-colors',
                                                data.framework === item.value ? 'border-primary bg-primary/5 font-medium' : 'hover:bg-muted',
                                            )}
                                            aria-pressed={data.framework === item.value}
                                        >
                                            {item.label}
                                        </button>
                                    ))}
                                </div>
                                <InputError message={errors.framework} />
                            </div>
                        </CardContent>
                    </Card>
                )}

                {step === 1 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Servers</CardTitle>
                            <CardDescription>
                                Pick one or more servers. Every deploy goes to all of them; the leader runs migrations and the scheduler.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {options.servers.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    No app, web or worker servers yet.{' '}
                                    <Link href="/servers/create" className="underline">
                                        Create a server
                                    </Link>
                                    .
                                </p>
                            )}
                            {options.servers.map((server) => {
                                const selected = data.server_ids.includes(server.id);
                                const problem = serverProblem(server);

                                return (
                                    <div
                                        key={server.id}
                                        className={cn('flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2', selected && 'border-primary')}
                                    >
                                        <Checkbox
                                            id={`server-${server.id}`}
                                            checked={selected}
                                            onCheckedChange={(checked) => toggleServer(server.id, checked === true)}
                                        />
                                        <label htmlFor={`server-${server.id}`} className="min-w-0 flex-1 cursor-pointer">
                                            <span className="font-medium">{server.name}</span>
                                            <span className="text-muted-foreground block text-xs">
                                                {server.type_label} · {server.ipv4 ?? 'no IP'} · {server.php_runtime ?? 'no PHP'}
                                                {server.php_versions.length > 0 && ` ${server.php_versions.join(', ')}`} ·{' '}
                                                {formatBytes(server.memory_bytes)}
                                                {server.status !== 'active' && ` · ${server.status}`}
                                            </span>
                                        </label>
                                        {problem && <Badge variant="outline">{problem}</Badge>}
                                        {selected && (
                                            <label className="flex items-center gap-1.5 text-xs">
                                                <input
                                                    type="radio"
                                                    name="leader"
                                                    checked={data.leader_server_id === server.id}
                                                    onChange={() => setData('leader_server_id', server.id)}
                                                />
                                                Leader
                                            </label>
                                        )}
                                    </div>
                                );
                            })}
                            <InputError message={errors.server_ids} />
                            <InputError message={errors.leader_server_id} />
                        </CardContent>
                    </Card>
                )}

                {step === 2 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Runtime</CardTitle>
                            <CardDescription>
                                How the site runs and is built. Builds happen on a builder, never on managed servers unless you opt in.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Runtime</Label>
                                    <Select value={data.runtime} onValueChange={chooseRuntime}>
                                        <SelectTrigger aria-label="Runtime">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.runtimes
                                                .filter((item) => framework.runtimes.includes(item.value))
                                                .map((item) => (
                                                    <SelectItem key={item.value} value={item.value}>
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.runtime} />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Build</Label>
                                    <Select value={data.build_mode} onValueChange={(value) => setData('build_mode', value)}>
                                        <SelectTrigger aria-label="Build mode">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.build_modes
                                                .filter((mode) => runtime.build_modes.includes(mode.value))
                                                .map((mode) => (
                                                    <SelectItem key={mode.value} value={mode.value}>
                                                        {mode.label}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                    {data.build_mode === 'on-server' && (
                                        <p className="text-muted-foreground text-xs">Requires at least 2 GB of RAM on every server.</p>
                                    )}
                                    <InputError message={errors.build_mode} />
                                </div>
                                {runtime.is_php && (
                                    <div className="grid gap-2">
                                        <Label>PHP version</Label>
                                        <Select value={data.php_version || undefined} onValueChange={(value) => setData('php_version', value)}>
                                            <SelectTrigger aria-label="PHP version">
                                                <SelectValue placeholder="Pick a version" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {phpVersions.map((version) => (
                                                    <SelectItem key={version} value={version}>
                                                        PHP {version}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {phpVersions.length === 0 && (
                                            <p className="text-xs text-amber-600">No PHP version is installed on all selected servers.</p>
                                        )}
                                        <InputError message={errors.php_version} />
                                    </div>
                                )}
                                {!runtime.is_php && !runtime.container && (
                                    <div className="grid gap-2">
                                        <Label>Node version</Label>
                                        <Select value={data.node_version} onValueChange={(value) => setData('node_version', value)}>
                                            <SelectTrigger aria-label="Node version">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.node_versions.map((version) => (
                                                    <SelectItem key={version} value={version}>
                                                        Node {version}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.node_version} />
                                    </div>
                                )}
                                {(runtime.is_php || data.runtime === 'static') && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="web_directory">Web directory</Label>
                                        <Input
                                            id="web_directory"
                                            value={data.web_directory}
                                            onChange={(e) => setData('web_directory', e.target.value)}
                                            placeholder="public"
                                        />
                                        <InputError message={errors.web_directory} />
                                    </div>
                                )}
                                {runtime.proxies && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="app_port">App port</Label>
                                        <Input
                                            id="app_port"
                                            type="number"
                                            min={1024}
                                            max={65535}
                                            value={data.app_port}
                                            onChange={(e) => setData('app_port', e.target.value)}
                                            placeholder="Assigned automatically"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Exposed to the app as PORT; the edge proxies to 127.0.0.1:port.
                                        </p>
                                        <InputError message={errors.app_port} />
                                    </div>
                                )}
                                {data.runtime === 'docker' && (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="docker_image">Image (optional)</Label>
                                            <Input
                                                id="docker_image"
                                                value={data.docker_image}
                                                onChange={(e) => setData('docker_image', e.target.value)}
                                                placeholder="ghcr.io/acme/shop:latest"
                                            />
                                            <InputError message={errors.docker_image} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="dockerfile">Dockerfile</Label>
                                            <Input
                                                id="dockerfile"
                                                value={data.dockerfile}
                                                onChange={(e) => setData('dockerfile', e.target.value)}
                                                placeholder="Dockerfile"
                                            />
                                            <InputError message={errors.dockerfile} />
                                        </div>
                                    </>
                                )}
                                {data.runtime === 'compose' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="compose_file">Compose file</Label>
                                        <Input
                                            id="compose_file"
                                            value={data.compose_file}
                                            onChange={(e) => setData('compose_file', e.target.value)}
                                            placeholder="compose.yaml"
                                        />
                                        <InputError message={errors.compose_file} />
                                    </div>
                                )}
                            </div>
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox checked={data.isolated} onCheckedChange={(checked) => setData('isolated', checked === true)} />
                                <span>
                                    User isolation
                                    <span className="text-muted-foreground block text-xs">
                                        Run the site as its own Linux user (other sites on the server cannot read its files).
                                    </span>
                                </span>
                            </label>
                            {options.test_domain && (
                                <label className="flex items-start gap-2 text-sm">
                                    <Checkbox
                                        checked={data.test_domain_enabled}
                                        onCheckedChange={(checked) => setData('test_domain_enabled', checked === true)}
                                    />
                                    <span>
                                        Test domain
                                        <span className="text-muted-foreground block text-xs">
                                            {data.slug || slugify(data.name) || 'site'}.{options.test_domain}
                                        </span>
                                    </span>
                                </label>
                            )}
                        </CardContent>
                    </Card>
                )}

                {step === 3 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Repository</CardTitle>
                            <CardDescription>
                                A read-only deploy key is generated for the repository and added through the provider API.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RepositoryPicker
                                connections={options.connections}
                                value={data}
                                onChange={(value) => setData((current) => ({ ...current, ...value }))}
                                errors={errors}
                                canManageSourceControl={canManageSourceControl}
                            />
                        </CardContent>
                    </Card>
                )}

                {step === 4 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Review</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y text-sm">
                                {[
                                    ['Name', data.name],
                                    ['Framework', framework.label],
                                    ['Runtime', `${runtime.label}${runtime.is_php ? ` · PHP ${data.php_version}` : ''}`],
                                    ['Build', options.build_modes.find((mode) => mode.value === data.build_mode)?.label ?? data.build_mode],
                                    [
                                        'Servers',
                                        selectedServers
                                            .map((server) => `${server.name}${server.id === data.leader_server_id ? ' (leader)' : ''}`)
                                            .join(', '),
                                    ],
                                    ['Repository', data.repository ? `${data.repository}@${data.branch}` : 'None'],
                                    ['User isolation', data.isolated ? 'Yes' : 'No'],
                                ].map(([label, value]) => (
                                    <div key={label} className="grid grid-cols-3 gap-2 py-2">
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd className="col-span-2">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                <div className="flex items-center justify-between gap-2">
                    <Button type="button" variant="ghost" onClick={() => setStep(Math.max(0, step - 1))} disabled={step === 0 || processing}>
                        Back
                    </Button>
                    <Button type="submit" disabled={!canContinue[step] || processing}>
                        {processing && <Loader2 className="animate-spin" />}
                        {step === STEPS.length - 1 ? 'Create site' : 'Continue'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
