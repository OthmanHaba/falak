import { Button } from '@/components/kiln/button';
import { Callout } from '@/components/kiln/callout';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Segmented } from '@/components/kiln/segmented';
import { StatusBadge } from '@/components/kiln/status';
import { Tag } from '@/components/kiln/tag';
import { errorMessage, requestJson } from '@/lib/http';
import { useForm } from '@inertiajs/react';
import { ExternalLink, FolderGit2, Plus, Settings2, ShieldCheck, Trash2, Unplug } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { type GitHubAppState, type GitHubInstallation } from '../types';
import { ProviderIcon } from './provider-icon';

const STATUS_LABELS: Record<GitHubInstallation['status'], { status: string; label: string }> = {
    active: { status: 'active', label: 'Active' },
    suspended: { status: 'degraded', label: 'Suspended on GitHub' },
    disconnected: { status: 'offline', label: 'Uninstalled on GitHub' },
};

/** POSTs the manifest to GitHub the way the manifest flow requires: a real form submission from the browser. */
function submitManifest(action: string, manifest: unknown) {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = action;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'manifest';
    input.value = JSON.stringify(manifest);
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function Permissions({ state }: { state: GitHubAppState }) {
    return (
        <ul className="text-fg-muted grid gap-1.5 text-xs" aria-label="Requested access">
            {Object.entries(state.permissions).map(([permission, level]) => (
                <li key={permission} className="flex items-center gap-2">
                    <ShieldCheck className="text-success size-3.5 shrink-0" aria-hidden />
                    <span className="text-fg capitalize">{permission.replace(/_/g, ' ')}</span>
                    <Tag>{level === 'read' ? 'Read-only' : level}</Tag>
                </li>
            ))}
            <li className="flex items-center gap-2">
                <ShieldCheck className="text-success size-3.5 shrink-0" aria-hidden />
                <span className="text-fg">Events</span>
                {state.events.map((event) => (
                    <Tag key={event} mono>
                        {event}
                    </Tag>
                ))}
            </li>
        </ul>
    );
}

/** Not created yet: one click registers a private GitHub App for this organization. */
function CreateApp({ state, returnTo }: { state: GitHubAppState; returnTo: string | null }) {
    const [owner, setOwner] = useState<'personal' | 'organization'>('personal');
    const [organization, setOrganization] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        setError(null);
        setPending(true);
        requestJson<{ data: { action: string; manifest: unknown } }>(route('source-control.github-app.manifest'), 'POST', {
            organization: owner === 'organization' ? organization.trim() : null,
            return_to: returnTo,
        })
            .then(({ data }) => submitManifest(data.action, data.manifest))
            .catch((e: unknown) => {
                setError(errorMessage(e, 'Could not start the GitHub setup.'));
                setPending(false);
            });
    };

    return (
        <form onSubmit={submit} className="grid gap-5 p-5 md:grid-cols-[1fr_16rem]" data-testid="github-app-create">
            <div className="grid content-start gap-4">
                <div className="grid gap-1.5">
                    <p className="text-fg text-sm">
                        Kiln creates a private GitHub App for this organization in one click. You choose the repositories on GitHub and can change
                        that any time — no personal access tokens, no deploy keys, no per-repository webhooks.
                    </p>
                    <p className="text-fg-faint text-xs">Builds clone with short-lived installation tokens (valid for one hour, never stored).</p>
                </div>
                <div className="grid gap-3">
                    <Segmented
                        label="Create the app on"
                        value={owner}
                        onValueChange={setOwner}
                        options={[
                            { value: 'personal', label: 'Personal account' },
                            { value: 'organization', label: 'GitHub organization' },
                        ]}
                        className="w-fit"
                    />
                    {owner === 'organization' && (
                        <Field label="Organization" hint="You must be an owner of the organization on GitHub." required>
                            <Input
                                mono
                                value={organization}
                                onChange={(event) => setOrganization(event.target.value)}
                                placeholder="acme-inc"
                                aria-label="GitHub organization"
                                autoFocus
                                className="max-w-xs"
                            />
                        </Field>
                    )}
                </div>
                {error && <Callout tone="danger">{error}</Callout>}
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="primary" type="submit" loading={pending} disabled={owner === 'organization' && organization.trim() === ''}>
                        <ProviderIcon provider="github" className="size-4" /> Connect GitHub
                    </Button>
                    <span className="text-fg-faint text-xs">You confirm the app on github.com, then pick repositories.</span>
                </div>
            </div>
            <div className="border-border grid content-start gap-3 rounded-md border p-3.5">
                <span className="text-fg text-xs font-medium">Kiln asks for</span>
                <Permissions state={state} />
            </div>
        </form>
    );
}

export function GitHubAppCard({
    state,
    canManage,
    returnTo,
    onBrowse,
    onDisconnect,
}: {
    state: GitHubAppState;
    canManage: boolean;
    returnTo: string | null;
    onBrowse: (installation: GitHubInstallation) => void;
    onDisconnect: (installation: GitHubInstallation) => void;
}) {
    const [deleting, setDeleting] = useState(false);
    const removal = useForm({ name: '' });
    const app = state.app;
    const installHref = route('source-control.github-app', returnTo ? { return_to: returnTo } : {});

    const deleteApp = (name: string) =>
        new Promise<void>((resolve) => {
            removal.transform(() => ({ name }));
            removal.delete(route('source-control.github-app.destroy'), {
                preserveScroll: true,
                onSuccess: () => setDeleting(false),
                onFinish: () => resolve(),
            });
        });

    return (
        <div className="border-border bg-surface-1 rounded-lg border" data-testid="github-app">
            <div className="border-border flex flex-wrap items-center gap-3 border-b px-5 py-4">
                <span className="border-border bg-surface-2 text-fg flex size-9 items-center justify-center rounded-md border">
                    <ProviderIcon provider="github" className="size-4.5" />
                </span>
                <div className="grid min-w-0 flex-1">
                    <span className="text-fg flex items-center gap-2 text-sm font-medium">
                        {app ? app.name : 'GitHub'}
                        {app?.source === 'env' ? <Tag>Configured by the operator</Tag> : !app && <Tag>Recommended</Tag>}
                    </span>
                    <span className="text-fg-muted truncate text-xs">
                        {!app ? (
                            'Install a GitHub App and grant it the repositories Kiln deploys.'
                        ) : (
                            <>
                                {app.owner ? `Owned by ${app.owner}` : 'GitHub App'} ·{' '}
                                {app.last_delivery_at ? (
                                    <>
                                        last webhook <RelativeTime value={app.last_delivery_at} />
                                    </>
                                ) : (
                                    'no webhook deliveries yet'
                                )}
                            </>
                        )}
                    </span>
                </div>
                {app && (
                    <div className="flex flex-wrap gap-1.5">
                        {canManage && app.installable && (
                            <Button asChild size="sm" variant="primary">
                                <a href={installHref}>
                                    <Plus /> Add installation
                                </a>
                            </Button>
                        )}
                        {app.settings_url && (
                            <Button asChild size="sm">
                                <a href={app.settings_url} target="_blank" rel="noreferrer">
                                    <Settings2 /> App settings
                                </a>
                            </Button>
                        )}
                        {app.html_url && !app.settings_url && (
                            <Button asChild size="sm">
                                <a href={app.html_url} target="_blank" rel="noreferrer">
                                    <ExternalLink /> View on GitHub
                                </a>
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {!app ? (
                canManage ? (
                    <CreateApp state={state} returnTo={returnTo} />
                ) : (
                    <p className="text-fg-muted p-5 text-sm">Ask an owner or admin of this organization to connect GitHub.</p>
                )
            ) : state.installations.length === 0 ? (
                <div className="grid gap-2 p-5 text-sm">
                    <p className="text-fg">Not installed yet.</p>
                    <p className="text-fg-muted text-xs">
                        Install the app on your account or organization and choose which repositories Kiln may deploy.
                    </p>
                    {canManage && app.installable && (
                        <Button asChild variant="primary" className="w-fit">
                            <a href={installHref}>Install on GitHub</a>
                        </Button>
                    )}
                </div>
            ) : (
                <ul className="divide-border divide-y" aria-label="GitHub installations">
                    {state.installations.map((installation) => {
                        const status = STATUS_LABELS[installation.status];

                        return (
                            <li
                                key={installation.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3"
                                data-testid="github-installation"
                            >
                                <span className="grid min-w-0 flex-1">
                                    <span className="text-fg truncate text-sm font-medium">{installation.account ?? installation.name}</span>
                                    <span className="text-fg-faint truncate text-xs">
                                        {installation.target_type === 'Organization' ? 'Organization' : 'Personal account'} ·{' '}
                                        {installation.repositories_count === null
                                            ? 'repositories not listed yet'
                                            : `${installation.repositories_count} ${installation.repositories_count === 1 ? 'repository' : 'repositories'}`}
                                    </span>
                                </span>
                                <StatusBadge status={status.status} label={status.label} />
                                <div className="flex flex-wrap gap-1.5">
                                    {installation.status === 'active' && (
                                        <Button size="sm" variant="ghost" onClick={() => onBrowse(installation)}>
                                            <FolderGit2 /> Repositories
                                        </Button>
                                    )}
                                    {installation.status !== 'disconnected' && (
                                        <Button asChild size="sm">
                                            <a href={installation.manage_url} target="_blank" rel="noreferrer">
                                                <ExternalLink /> Manage access on GitHub
                                            </a>
                                        </Button>
                                    )}
                                    {installation.status === 'disconnected' && canManage && app.installable && (
                                        <Button asChild size="sm" variant="primary">
                                            <a href={installHref}>Reinstall</a>
                                        </Button>
                                    )}
                                    {canManage && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => onDisconnect(installation)}
                                            aria-label={`Disconnect ${installation.name}`}
                                        >
                                            <Unplug /> Disconnect
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {app && (
                <div className="border-border text-fg-faint flex flex-wrap items-center gap-x-3 gap-y-1 border-t px-5 py-3 text-xs">
                    <span>
                        Webhook <span className="text-fg-muted font-mono">{app.webhook_url}</span> — must be reachable from GitHub for push-to-deploy.
                    </span>
                    {canManage && app.source === 'registered' && (
                        <Button size="sm" variant="ghost" className="ml-auto" onClick={() => setDeleting(true)}>
                            <Trash2 /> Delete app
                        </Button>
                    )}
                </div>
            )}

            {app?.source === 'registered' && (
                <ConfirmDestructive
                    open={deleting}
                    onOpenChange={setDeleting}
                    title={`Delete ${app.name}`}
                    description="Kiln uninstalls the app from every account, disconnects its installations and forgets its credentials. Sites using them can't deploy until they point at another connection. Delete the app registration on GitHub afterwards (App settings → Advanced)."
                    confirmText={app.name}
                    confirmLabel="Delete app"
                    onConfirm={deleteApp}
                    processing={removal.processing}
                    error={removal.errors.name}
                />
            )}
        </div>
    );
}
