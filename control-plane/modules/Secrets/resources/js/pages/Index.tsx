import { AppShell, Button, DataTable, PageHeader, RelativeTime, Select, Tag, Tooltip, toast } from '@/components/falak';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowUpFromLine, KeyRound, Plus, Waypoints } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { CreateSecretDialog } from '../components/create-secret-dialog';
import { PromoteDialog } from '../components/promote-dialog';
import { SecretDetailDialog } from '../components/secret-detail-dialog';
import { SCOPE_LABELS, type ProviderOption, type ScopeOption, type SecretAbilities, type SecretRow } from '../types';

interface Props {
    context: 'project' | 'organization';
    project: { id: string; name: string; production_slug: string | null } | null;
    /** Where secrets can be created from this page (project: project, environments, site services). */
    scopes: ScopeOption[];
    secrets: SecretRow[];
    /** The organization's providers, for linked secrets. */
    providers: ProviderOption[];
    can: SecretAbilities;
    reauth_requires_code: boolean;
}

/**
 * /projects/{p}/settings/secrets (the project's secrets, its environments' and services', plus the inherited
 * organization ones) and /settings/secrets (the organization's own). Names and metadata only.
 */
export default function Index({ context, project, scopes, secrets, providers, can, reauth_requires_code }: Props) {
    const [filter, setFilter] = useState('all');
    const [creating, setCreating] = useState(false);
    const [promoting, setPromoting] = useState(false);
    const [openId, setOpenId] = useState<string | null>(null);
    const open = secrets.find((secret) => secret.id === openId) ?? null;

    const environments = scopes.filter((scope) => scope.scope === 'environment');
    const services = scopes.filter((scope) => scope.scope === 'service');
    const refresh = () => router.reload({ only: ['secrets'] });

    const rows = useMemo(() => {
        if (filter === 'all') return secrets;
        if (filter === 'project' || filter === 'organization') return secrets.filter((secret) => secret.scope === filter);
        // An environment: its own secrets and those of its services.
        const serviceIds = new Set(services.filter((service) => service.environment_id === filter).map((service) => service.id));

        return secrets.filter(
            (secret) =>
                (secret.scope === 'environment' && secret.scope_id === filter) || (secret.scope === 'service' && serviceIds.has(secret.scope_id)),
        );
    }, [filter, secrets, services]);

    const actions = (
        <div className="flex items-center gap-2">
            {context === 'organization' && (
                <Button icon={<Waypoints />} onClick={() => router.visit('/settings/secrets/providers')}>
                    Providers
                </Button>
            )}
            {context === 'project' && can.promote && services.length > 0 && (
                <Button icon={<ArrowUpFromLine />} onClick={() => setPromoting(true)}>
                    Promote a variable
                </Button>
            )}
            {can.manage && (
                <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                    New secret
                </Button>
            )}
        </div>
    );

    const table = (
        <div className="grid gap-3">
            {context === 'project' && (
                <div className="flex items-center gap-2">
                    <Select
                        size="sm"
                        className="w-56"
                        value={filter}
                        onValueChange={setFilter}
                        options={[
                            { value: 'all', label: 'All scopes' },
                            { value: 'project', label: 'Project' },
                            ...environments.map((environment) => ({ value: environment.id, label: `Environment: ${environment.label}` })),
                            { value: 'organization', label: 'Organization (inherited)' },
                        ]}
                    />
                    <span className="text-fg-faint text-xs">Nearest scope wins: service, environment, project, organization.</span>
                </div>
            )}
            <DataTable
                label="Secrets"
                rows={rows}
                rowKey={(row) => row.id}
                onRowClick={(row) => setOpenId(row.id)}
                defaultSort={{ column: 'name', direction: 'asc' }}
                empty={{
                    icon: <KeyRound />,
                    title: 'No secrets yet',
                    description: (
                        <>
                            Store API keys and passwords once and reference them from variables as{' '}
                            <code className="font-mono">{'${{ secrets.NAME }}'}</code>.
                        </>
                    ),
                    action: can.manage ? (
                        <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                            New secret
                        </Button>
                    ) : undefined,
                }}
                columns={[
                    {
                        id: 'name',
                        header: 'Name',
                        sortValue: (row) => row.name,
                        cell: (row) => (
                            <span className="flex min-w-0 items-center gap-1.5">
                                <span className="truncate font-mono text-xs font-medium">{row.name}</span>
                                {row.sensitive && <Tag tone="warning">Sensitive</Tag>}
                                {row.kind === 'linked' && <Tag tone="info">Linked</Tag>}
                                {row.watch_minutes && <Tag tone="faint">Watched</Tag>}
                                {row.rotation_due_at && new Date(row.rotation_due_at) < new Date() && <Tag tone="danger">Rotation due</Tag>}
                            </span>
                        ),
                    },
                    {
                        id: 'scope',
                        header: 'Scope',
                        sortValue: (row) => `${row.scope}:${row.scope_label}`,
                        cell: (row) => (
                            <span className="flex items-center gap-1.5 text-sm">
                                <Tag tone={row.scope === 'organization' ? 'faint' : 'neutral'}>{SCOPE_LABELS[row.scope]}</Tag>
                                {row.scope !== 'organization' && row.scope !== 'project' && <span className="truncate">{row.scope_label}</span>}
                            </span>
                        ),
                    },
                    {
                        id: 'version',
                        header: 'Version',
                        align: 'right',
                        sortValue: (row) => row.current_version,
                        cell: (row) => <span className="tabular font-mono text-xs">v{row.current_version}</span>,
                    },
                    {
                        id: 'used',
                        header: 'Used by',
                        hideOnMobile: true,
                        sortValue: (row) => row.used_by?.length ?? 0,
                        cell: (row) => usedBy(row),
                    },
                    {
                        id: 'accessed',
                        header: 'Last accessed',
                        hideOnMobile: true,
                        sortValue: (row) => row.last_accessed_at ?? '',
                        cell: (row) => <RelativeTime value={row.last_accessed_at} fallback="Never" />,
                    },
                    {
                        id: 'updated',
                        header: 'Updated',
                        hideOnMobile: true,
                        sortValue: (row) => row.updated_at,
                        cell: (row) => <RelativeTime value={row.updated_at} />,
                    },
                ]}
            />
        </div>
    );

    const dialogs = (
        <>
            <CreateSecretDialog
                open={creating}
                scopes={scopes}
                providers={providers}
                onClose={() => setCreating(false)}
                onCreated={() => {
                    setCreating(false);
                    toast.success('Secret created');
                    refresh();
                }}
            />
            <PromoteDialog
                open={promoting}
                services={services}
                onClose={() => setPromoting(false)}
                onPromoted={() => {
                    setPromoting(false);
                    toast.success('Variable promoted', 'It now references the secret; redeploy to apply.');
                    refresh();
                }}
            />
            <SecretDetailDialog
                secret={open}
                providers={providers}
                can={can}
                reauthRequiresCode={reauth_requires_code}
                onClose={() => setOpenId(null)}
                onChanged={refresh}
            />
        </>
    );

    if (context === 'organization') {
        return (
            <SettingsLayout
                title="Secrets"
                description="Organization secrets are inherited by every project, environment and service, unless a nearer scope defines the same name."
                actions={actions}
                wide
            >
                {table}
                {dialogs}
            </SettingsLayout>
        );
    }

    return (
        <AppShell>
            <Head title={`Secrets · ${project?.name ?? ''}`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <div className="grid gap-3">
                    {project && (
                        <Link href={`/projects/${project.id}/settings`} className="text-fg-muted hover:text-fg flex w-fit items-center gap-1 text-xs">
                            <ArrowLeft className="size-3.5" aria-hidden /> Project settings
                        </Link>
                    )}
                    <PageHeader title="Secrets" description={project?.name} actions={actions} />
                </div>
                {table}
            </div>
            {dialogs}
        </AppShell>
    );
}

function usedBy(row: SecretRow): ReactNode {
    const users = row.used_by ?? [];
    if (users.length === 0) return <span className="text-fg-faint text-xs">Not referenced</span>;

    return (
        <Tooltip content={users.map((user) => `${user.name}: ${user.variables.join(', ')}`).join('\n')}>
            <span className="text-sm">{users.length === 1 ? users[0].name : `${users.length} services`}</span>
        </Tooltip>
    );
}
