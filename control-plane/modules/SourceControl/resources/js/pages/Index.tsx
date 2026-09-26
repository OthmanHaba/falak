import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { GitBranch, GitCommitHorizontal, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { ProviderIcon } from '../components/provider-icon';
import { AUTH_LABELS, type ConnectionRow, type ProviderOption, type ProviderValue, type PushRow } from '../types';

interface Props {
    connections: ConnectionRow[];
    pushes: PushRow[];
    providers: ProviderOption[];
    githubApp: boolean;
    canManage: boolean;
}

type ManualAuth = 'token' | 'basic' | 'none';

interface ConnectForm {
    provider: ProviderValue;
    auth_type: ManualAuth;
    name: string;
    base_url: string;
    token: string;
    username: string;
    password: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Source control', href: '/source-control' }];

const MANUAL_AUTH: Record<ProviderValue, ManualAuth[]> = {
    github: ['token'],
    gitlab: ['token'],
    bitbucket: ['basic', 'token'],
    custom: ['none'],
};

const TOKEN_HINTS: Record<ProviderValue, string> = {
    github: 'Fine-grained or classic token with repository contents, deploy keys and webhooks access.',
    gitlab: 'Personal, group or project access token with the api scope.',
    bitbucket: 'Repository or workspace access token with repository and webhook scopes.',
    custom: '',
};

export default function Index({ connections, pushes, providers, githubApp, canManage }: Props) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [connecting, setConnecting] = useState<ProviderOption | null>(null);
    const [deleting, setDeleting] = useState<ConnectionRow | null>(null);
    const form = useForm<ConnectForm>({ provider: 'github', auth_type: 'token', name: '', base_url: '', token: '', username: '', password: '' });
    const removal = useForm({ name: '' });

    const openConnect = (provider: ProviderOption) => {
        form.clearErrors();
        form.setData({
            provider: provider.value,
            auth_type: MANUAL_AUTH[provider.value][0],
            name: '',
            base_url: '',
            token: '',
            username: '',
            password: '',
        });
        setConnecting(provider);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('source-control.connections.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setConnecting(null);
            },
        });
    };

    const destroy: FormEventHandler = (event) => {
        event.preventDefault();

        if (!deleting) return;

        removal.delete(route('source-control.connections.destroy', deleting.id), {
            preserveScroll: true,
            onSuccess: () => {
                removal.reset();
                setDeleting(null);
            },
        });
    };

    const connectionName = (id: string) => connections.find((connection) => connection.id === id)?.name ?? '—';
    const provider = form.data.provider;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Source control" />
            <div className="space-y-6 p-4">
                <Heading title="Source control" description="Git providers Kiln deploys from: repositories, deploy keys and push webhooks" />

                {errors.oauth && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.oauth}</AlertDescription>
                    </Alert>
                )}

                {canManage && (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {providers.map((option) => (
                            <Card key={option.value} className="gap-3">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <ProviderIcon provider={option.value} className="size-4" /> {option.label}
                                    </CardTitle>
                                    <CardDescription>
                                        {option.has_api
                                            ? 'Repositories, branches, deploy keys and webhooks via API.'
                                            : 'Any SSH-reachable git server with a deploy key.'}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    {option.oauth && (
                                        <Button size="sm" asChild>
                                            <a href={route('source-control.connect', option.value)}>Connect with OAuth</a>
                                        </Button>
                                    )}
                                    {option.value === 'github' && githubApp && (
                                        <Button size="sm" variant="secondary" asChild>
                                            <a href={route('source-control.github-app')}>Install GitHub App</a>
                                        </Button>
                                    )}
                                    <Button size="sm" variant="outline" onClick={() => openConnect(option)}>
                                        <Plus /> {option.has_api ? 'Use a token' : 'Add server'}
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                <section className="space-y-3">
                    <h3 className="text-sm font-medium">Connections</h3>
                    {connections.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                                <GitBranch className="text-muted-foreground size-10" />
                                <p className="font-medium">No git providers connected</p>
                                <p className="text-muted-foreground text-sm">
                                    Connect GitHub, GitLab, Bitbucket or a custom git server to deploy sites from it.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Provider</TableHead>
                                        <TableHead>Account</TableHead>
                                        <TableHead>Auth</TableHead>
                                        <TableHead>Deploy keys</TableHead>
                                        <TableHead>Webhooks</TableHead>
                                        <TableHead>Connected</TableHead>
                                        {canManage && <TableHead className="w-12" />}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {connections.map((connection) => (
                                        <TableRow key={connection.id}>
                                            <TableCell className="font-medium">{connection.name}</TableCell>
                                            <TableCell>
                                                <span className="flex items-center gap-2">
                                                    <ProviderIcon provider={connection.provider} className="size-4" />
                                                    {connection.provider_label}
                                                </span>
                                                {connection.base_url && (
                                                    <span className="text-muted-foreground block text-xs">{connection.base_url}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">{connection.account ?? '—'}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{AUTH_LABELS[connection.auth_type]}</Badge>
                                            </TableCell>
                                            <TableCell className="tabular-nums">{connection.deploy_keys_count}</TableCell>
                                            <TableCell className="tabular-nums">{connection.webhooks_count}</TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {formatDistanceToNow(new Date(connection.created_at), { addSuffix: true })}
                                            </TableCell>
                                            {canManage && (
                                                <TableCell>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => {
                                                            removal.reset();
                                                            removal.clearErrors();
                                                            setDeleting(connection);
                                                        }}
                                                        aria-label={`Disconnect ${connection.name}`}
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    )}
                </section>

                <section className="space-y-3">
                    <h3 className="text-sm font-medium">Recent pushes</h3>
                    {pushes.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No push webhooks received yet.</p>
                    ) : (
                        <Card className="py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Repository</TableHead>
                                        <TableHead>Branch</TableHead>
                                        <TableHead>Commit</TableHead>
                                        <TableHead>Author</TableHead>
                                        <TableHead>Connection</TableHead>
                                        <TableHead>Received</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {pushes.map((push) => (
                                        <TableRow key={push.id}>
                                            <TableCell className="font-mono text-xs break-all">{push.repository}</TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">{push.branch}</Badge>
                                            </TableCell>
                                            <TableCell className="max-w-md">
                                                <span className="flex items-center gap-2">
                                                    <GitCommitHorizontal className="text-muted-foreground size-4 shrink-0" />
                                                    {push.url ? (
                                                        <a
                                                            href={push.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-mono text-xs underline-offset-2 hover:underline"
                                                        >
                                                            {push.sha.slice(0, 7)}
                                                        </a>
                                                    ) : (
                                                        <span className="font-mono text-xs">{push.sha.slice(0, 7)}</span>
                                                    )}
                                                    <span className="truncate">{push.message}</span>
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-sm">{push.author ?? push.pusher ?? '—'}</TableCell>
                                            <TableCell className="text-muted-foreground text-sm">{connectionName(push.connection_id)}</TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {formatDistanceToNow(new Date(push.received_at), { addSuffix: true })}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    )}
                </section>
            </div>

            <Dialog open={connecting !== null} onOpenChange={(value) => !value && setConnecting(null)}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Connect {connecting?.label}</DialogTitle>
                            <DialogDescription>
                                {provider === 'custom'
                                    ? 'Kiln generates a deploy key per site; add it to your git server and point its push webhook at the URL shown on the site.'
                                    : 'Credentials are verified with the provider and stored encrypted.'}
                            </DialogDescription>
                        </DialogHeader>

                        {MANUAL_AUTH[provider].length > 1 && (
                            <div className="flex gap-2" role="radiogroup" aria-label="Authentication">
                                {MANUAL_AUTH[provider].map((auth) => (
                                    <Button
                                        key={auth}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        role="radio"
                                        aria-checked={form.data.auth_type === auth}
                                        className={cn(form.data.auth_type === auth && 'border-primary')}
                                        onClick={() => form.setData('auth_type', auth)}
                                    >
                                        {AUTH_LABELS[auth]}
                                    </Button>
                                ))}
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="connection-name">Name (optional)</Label>
                            <Input
                                id="connection-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={provider === 'custom' ? 'Internal git' : `${connecting?.label ?? ''} (account)`}
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        {provider !== 'bitbucket' && (
                            <div className="grid gap-2">
                                <Label htmlFor="connection-base-url">
                                    {provider === 'custom'
                                        ? 'Server URL (optional)'
                                        : provider === 'github'
                                          ? 'GitHub Enterprise URL (optional)'
                                          : 'Self-hosted URL (optional)'}
                                </Label>
                                <Input
                                    id="connection-base-url"
                                    value={form.data.base_url}
                                    onChange={(e) => form.setData('base_url', e.target.value)}
                                    placeholder={
                                        provider === 'gitlab'
                                            ? 'https://gitlab.example.com'
                                            : provider === 'github'
                                              ? 'https://github.example.com'
                                              : 'ssh://git@git.example.com'
                                    }
                                />
                                <InputError message={form.errors.base_url} />
                            </div>
                        )}

                        {form.data.auth_type === 'token' && (
                            <div className="grid gap-2">
                                <Label htmlFor="connection-token">Access token</Label>
                                <Input
                                    id="connection-token"
                                    type="password"
                                    autoComplete="off"
                                    value={form.data.token}
                                    onChange={(e) => form.setData('token', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">{TOKEN_HINTS[provider]}</p>
                                <InputError message={form.errors.token} />
                            </div>
                        )}

                        {form.data.auth_type === 'basic' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="connection-username">Username</Label>
                                    <Input
                                        id="connection-username"
                                        value={form.data.username}
                                        onChange={(e) => form.setData('username', e.target.value)}
                                    />
                                    <InputError message={form.errors.username} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="connection-password">App password</Label>
                                    <Input
                                        id="connection-password"
                                        type="password"
                                        autoComplete="off"
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                    />
                                    <InputError message={form.errors.password} />
                                </div>
                            </div>
                        )}

                        <InputError message={errors.credentials ?? form.errors.auth_type ?? form.errors.provider} />

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setConnecting(null)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>Connect</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <form onSubmit={destroy} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Disconnect {deleting?.name}?</DialogTitle>
                            <DialogDescription>
                                Its {deleting?.deploy_keys_count ?? 0} deploy key(s) and {deleting?.webhooks_count ?? 0} webhook(s) are removed from
                                the provider. Sites using it can no longer be deployed until they are pointed at another connection.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="confirm-name">
                                Type <span className="font-mono">{deleting?.name}</span> to confirm
                            </Label>
                            <Input id="confirm-name" value={removal.data.name} onChange={(e) => removal.setData('name', e.target.value)} />
                            <InputError message={removal.errors.name} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setDeleting(null)}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={removal.processing || removal.data.name !== deleting?.name}>
                                Disconnect
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
