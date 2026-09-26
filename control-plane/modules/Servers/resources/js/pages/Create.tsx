import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Loader2 } from 'lucide-react';
import { FormEventHandler, useMemo } from 'react';
import { useCatalog, type Image, type Region, type Size } from '../components/catalog';
import { type SshKeyOption, type StackComponent, type StackConfig } from '../types';

interface TypeOption {
    value: string;
    label: string;
    description: string;
    components: StackComponent[];
    defaults: StackConfig;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    types: TypeOption[];
    providers: (Option & { has_api: boolean })[];
    credentials: { id: string; name: string; provider: string }[];
    options: {
        php_versions: string[];
        php_runtimes: Option[];
        node_versions: string[];
        databases: Option[];
        caches: Option[];
    };
    sshKeys: SshKeyOption[];
    canManageProviders: boolean;
}

type CreateForm = {
    name: string;
    type: string;
    provider: string;
    credential_id: string;
    region: string;
    size: string;
    image: string;
    timezone: string;
    stack: StackConfig;
    ssh_key_ids: string[];
};

const NONE = 'none';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Servers', href: '/servers' },
    { title: 'Create', href: '/servers/create' },
];

function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <CardContent className="space-y-4">{children}</CardContent>
        </Card>
    );
}

function CatalogStatus({ loading, error }: { loading: boolean; error: string | null }) {
    if (loading) {
        return (
            <p className="text-muted-foreground flex items-center gap-2 text-xs">
                <Loader2 className="size-3 animate-spin" /> Loading…
            </p>
        );
    }

    return error ? <p className="text-destructive text-xs">{error}</p> : null;
}

export default function Create({ types, providers, credentials, options, sshKeys, canManageProviders }: Props) {
    const initialType = types.find((type) => type.value === 'app') ?? types[0];

    const { data, setData, post, processing, errors, transform } = useForm<CreateForm>({
        name: '',
        type: initialType.value,
        provider: providers.find((provider) => provider.has_api)?.value ?? 'custom',
        credential_id: '',
        region: '',
        size: '',
        image: '',
        timezone: 'UTC',
        stack: initialType.defaults,
        ssh_key_ids: [],
    });

    const errorBag = errors as Partial<Record<string, string>>;
    const selectedType = types.find((type) => type.value === data.type) ?? initialType;
    const provider = providers.find((option) => option.value === data.provider);
    const hasApi = provider?.has_api ?? false;
    const providerCredentials = credentials.filter((credential) => credential.provider === data.provider);
    const allows = (component: StackComponent) => selectedType.components.includes(component);

    const base = data.credential_id ? `/providers/${data.credential_id}` : null;
    const regions = useCatalog<Region>(hasApi && base ? `${base}/regions` : null);
    const sizes = useCatalog<Size>(hasApi && base && data.region ? `${base}/sizes?region=${encodeURIComponent(data.region)}` : null);
    const images = useCatalog<Image>(hasApi && base ? `${base}/images` : null);

    const sizeOptions = useMemo(
        () => sizes.items.filter((size) => size.regions.length === 0 || size.regions.includes(data.region)),
        [sizes.items, data.region],
    );

    const setStack = (patch: Partial<StackConfig>) => setData('stack', { ...data.stack, ...patch });

    const chooseType = (value: string) => {
        const type = types.find((option) => option.value === value);
        if (!type) return;
        setData((current) => ({ ...current, type: value, stack: type.defaults }));
    };

    const chooseProvider = (value: string) => {
        setData((current) => ({
            ...current,
            provider: value,
            credential_id: credentials.find((credential) => credential.provider === value)?.id ?? '',
            region: '',
            size: '',
            image: '',
        }));
    };

    const togglePhpVersion = (version: string, checked: boolean) => {
        const php = data.stack.php ?? { runtime: 'frankenphp', versions: [], default: null };
        const versions = checked ? [...new Set([...php.versions, version])].sort() : php.versions.filter((v) => v !== version);
        const fallback = versions.length > 0 ? versions[versions.length - 1] : null;
        const nextDefault = php.default && versions.includes(php.default) ? php.default : fallback;
        setStack({ php: { ...php, versions, default: nextDefault } });
    };

    const toggleSshKey = (id: string, checked: boolean) =>
        setData('ssh_key_ids', checked ? [...data.ssh_key_ids, id] : data.ssh_key_ids.filter((key) => key !== id));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        transform((form) => ({
            ...form,
            credential_id: hasApi ? form.credential_id : null,
            region: hasApi ? form.region : null,
            size: hasApi ? form.size : null,
            image: hasApi ? form.image : null,
            stack: {
                php: allows('php') ? form.stack.php : null,
                node: allows('node') ? form.stack.node : null,
                database: allows('database') ? form.stack.database : null,
                cache: allows('cache') ? form.stack.cache : null,
                docker: allows('docker') ? form.stack.docker : false,
            },
        }));

        post(route('servers.store'));
    };

    const nullableSelect = (value: string | null, onChange: (value: string | null) => void, items: Option[], id: string, noneLabel: string) => (
        <Select value={value ?? NONE} onValueChange={(next) => onChange(next === NONE ? null : next)}>
            <SelectTrigger id={id}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>{noneLabel}</SelectItem>
                {items.map((item) => (
                    <SelectItem key={item.value} value={item.value}>
                        {item.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create server" />
            <form onSubmit={submit} className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Heading title="Create server" description="Provision a new machine at a cloud provider, or bring your own and install the agent." />

                <Section title="1. Server">
                    <div className="grid gap-2 sm:max-w-sm">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="web-1" autoFocus />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup" aria-label="Server type">
                        {types.map((type) => (
                            <button
                                type="button"
                                role="radio"
                                aria-checked={data.type === type.value}
                                key={type.value}
                                onClick={() => chooseType(type.value)}
                                className={cn(
                                    'hover:border-primary/60 rounded-lg border p-3 text-left transition-colors',
                                    data.type === type.value && 'border-primary ring-primary/30 ring-2',
                                )}
                            >
                                <div className="text-sm font-medium">{type.label}</div>
                                <div className="text-muted-foreground mt-1 text-xs">{type.description}</div>
                            </button>
                        ))}
                    </div>
                    <InputError message={errors.type} />
                </Section>

                <Section title="2. Infrastructure" description="Where the machine runs.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="provider">Provider</Label>
                            <Select value={data.provider} onValueChange={chooseProvider}>
                                <SelectTrigger id="provider">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {providers.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.provider} />
                        </div>

                        {hasApi && (
                            <div className="grid gap-2">
                                <Label htmlFor="credential">Credential</Label>
                                {providerCredentials.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        No {provider?.label} credential yet.{' '}
                                        {canManageProviders ? (
                                            <Link href="/providers?add=1" className="text-primary underline">
                                                Add one
                                            </Link>
                                        ) : (
                                            'Ask an admin to add one.'
                                        )}
                                    </p>
                                ) : (
                                    <Select
                                        value={data.credential_id || undefined}
                                        onValueChange={(value) =>
                                            setData((current) => ({ ...current, credential_id: value, region: '', size: '', image: '' }))
                                        }
                                    >
                                        <SelectTrigger id="credential">
                                            <SelectValue placeholder="Choose a credential" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {providerCredentials.map((credential) => (
                                                <SelectItem key={credential.id} value={credential.id}>
                                                    {credential.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <InputError message={errors.credential_id} />
                            </div>
                        )}
                    </div>

                    {hasApi && data.credential_id && (
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="region">Region</Label>
                                <Select
                                    value={data.region || undefined}
                                    onValueChange={(value) => setData((current) => ({ ...current, region: value, size: '' }))}
                                >
                                    <SelectTrigger id="region" disabled={regions.loading || regions.items.length === 0}>
                                        <SelectValue placeholder="Choose a region" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {regions.items.map((region) => (
                                            <SelectItem key={region.id} value={region.id} disabled={!region.available}>
                                                {region.name}
                                                {region.country ? ` (${region.country})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <CatalogStatus loading={regions.loading} error={regions.error} />
                                <InputError message={errors.region} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="size">Size</Label>
                                <Select value={data.size || undefined} onValueChange={(value) => setData('size', value)}>
                                    <SelectTrigger id="size" disabled={!data.region || sizes.loading || sizeOptions.length === 0}>
                                        <SelectValue placeholder={data.region ? 'Choose a size' : 'Choose a region first'} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sizeOptions.map((size) => (
                                            <SelectItem key={size.id} value={size.id}>
                                                {size.name} · {size.cpus} vCPU · {(size.memory_mb / 1024).toFixed(size.memory_mb < 1024 ? 1 : 0)} GB ·{' '}
                                                {size.disk_gb} GB
                                                {size.arch !== 'amd64' ? ` · ${size.arch}` : ''}
                                                {size.price_monthly !== null ? ` · $${size.price_monthly.toFixed(2)}/mo` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <CatalogStatus loading={sizes.loading} error={sizes.error} />
                                <InputError message={errors.size} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="image">Image</Label>
                                <Select value={data.image || undefined} onValueChange={(value) => setData('image', value)}>
                                    <SelectTrigger id="image" disabled={images.loading || images.items.length === 0}>
                                        <SelectValue placeholder="Choose an image" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {images.items.map((image) => (
                                            <SelectItem key={image.id} value={image.id}>
                                                {image.name}
                                                {image.arch ? ` (${image.arch})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <CatalogStatus loading={images.loading} error={images.error} />
                                <InputError message={errors.image} />
                            </div>
                        </div>
                    )}

                    {!hasApi && (
                        <p className="text-muted-foreground text-sm">
                            After creating the server you will get a one-time install command to run as root on any Ubuntu LTS machine. The agent then
                            enrolls and provisioning starts automatically.
                        </p>
                    )}

                    <div className="grid gap-2 sm:max-w-sm">
                        <Label htmlFor="timezone">Timezone</Label>
                        <Input id="timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)} placeholder="UTC" />
                        <InputError message={errors.timezone} />
                    </div>
                </Section>

                {selectedType.components.length > 0 && (
                    <Section title="3. Software" description="Installed by the provisioning plan. You can add PHP versions later.">
                        {allows('php') && (
                            <div className="space-y-3">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="php-runtime">PHP runtime</Label>
                                        {nullableSelect(
                                            data.stack.php?.runtime ?? null,
                                            (runtime) =>
                                                setStack({
                                                    php: runtime
                                                        ? {
                                                              runtime,
                                                              versions: data.stack.php?.versions ?? [],
                                                              default: data.stack.php?.default ?? null,
                                                          }
                                                        : null,
                                                }),
                                            options.php_runtimes,
                                            'php-runtime',
                                            'No PHP',
                                        )}
                                        <InputError message={errorBag['stack.php.runtime'] ?? errorBag['stack.php']} />
                                    </div>
                                    {data.stack.php && data.stack.php.versions.length > 0 && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="php-default">Default PHP version</Label>
                                            <Select
                                                value={data.stack.php.default ?? undefined}
                                                onValueChange={(value) => data.stack.php && setStack({ php: { ...data.stack.php, default: value } })}
                                            >
                                                <SelectTrigger id="php-default">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {data.stack.php.versions.map((version) => (
                                                        <SelectItem key={version} value={version}>
                                                            PHP {version}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errorBag['stack.php.default']} />
                                        </div>
                                    )}
                                </div>
                                {data.stack.php && (
                                    <div className="grid gap-2">
                                        <Label>PHP versions</Label>
                                        <div className="flex flex-wrap gap-4">
                                            {options.php_versions.map((version) => (
                                                <label key={version} className="flex items-center gap-2 text-sm">
                                                    <Checkbox
                                                        checked={data.stack.php?.versions.includes(version) ?? false}
                                                        onCheckedChange={(checked) => togglePhpVersion(version, checked === true)}
                                                    />
                                                    {version}
                                                </label>
                                            ))}
                                        </div>
                                        <InputError message={errorBag['stack.php.versions']} />
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-3">
                            {allows('node') && (
                                <div className="grid gap-2">
                                    <Label htmlFor="node">Node.js</Label>
                                    {nullableSelect(
                                        data.stack.node,
                                        (node) => setStack({ node }),
                                        options.node_versions.map((version) => ({ value: version, label: `Node ${version}` })),
                                        'node',
                                        'No Node.js',
                                    )}
                                    <InputError message={errorBag['stack.node']} />
                                </div>
                            )}
                            {allows('database') && (
                                <div className="grid gap-2">
                                    <Label htmlFor="database">Database</Label>
                                    {nullableSelect(data.stack.database, (database) => setStack({ database }), options.databases, 'database', 'None')}
                                    <InputError message={errorBag['stack.database']} />
                                </div>
                            )}
                            {allows('cache') && (
                                <div className="grid gap-2">
                                    <Label htmlFor="cache">Cache</Label>
                                    {nullableSelect(data.stack.cache, (cache) => setStack({ cache }), options.caches, 'cache', 'None')}
                                    <InputError message={errorBag['stack.cache']} />
                                </div>
                            )}
                        </div>

                        {allows('docker') && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.stack.docker} onCheckedChange={(checked) => setStack({ docker: checked === true })} />
                                Install Docker Engine (with Compose and Buildx)
                            </label>
                        )}
                        <InputError message={errorBag['stack.docker']} />
                    </Section>
                )}

                <Section title="4. Access" description="Keys installed for the kiln user once the server is provisioned.">
                    {sshKeys.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No organization SSH keys.{' '}
                            <Link href={route('ssh-keys.index')} className="text-primary underline">
                                Add one
                            </Link>
                            .
                        </p>
                    ) : (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {sshKeys.map((key) => (
                                <label key={key.id} className="flex items-start gap-2 rounded-md border p-2 text-sm">
                                    <Checkbox
                                        checked={data.ssh_key_ids.includes(key.id)}
                                        onCheckedChange={(checked) => toggleSshKey(key.id, checked === true)}
                                    />
                                    <span>
                                        <span className="font-medium">{key.name}</span>
                                        <span className="text-muted-foreground block font-mono text-xs break-all">{key.fingerprint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                    <InputError message={errors.ssh_key_ids} />
                </Section>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" asChild>
                        <Link href={route('servers.index')}>Cancel</Link>
                    </Button>
                    <Button disabled={processing}>
                        {processing && <Loader2 className="animate-spin" />}
                        Create server
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
