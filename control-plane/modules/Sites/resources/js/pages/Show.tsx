import { CommandStatusBadge, type CommandStatus } from '@/components/command-log';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import SiteLayout, { type SiteHeader } from '@/layouts/site-layout';
import { Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { AlertTriangle, KeyRound, Terminal } from 'lucide-react';
import { CopyButton, DefinitionRow, SiteStatusBadge, Warnings } from '../components/site-ui';
import { TargetsCard } from '../components/targets-card';
import { type LaravelToggles, type SharedPathItem, type SiteStatus, type SiteTarget } from '../types';

interface Details {
    status: SiteStatus;
    runtime: string;
    runtime_label: string;
    build_mode: string;
    framework: string;
    is_laravel: boolean;
    php_version: string | null;
    node_version: string | null;
    web_directory: string;
    root_path: string;
    document_root: string;
    unix_user: string;
    isolated: boolean;
    app_port: number | null;
    docker_image: string | null;
    dockerfile: string | null;
    compose_file: string | null;
    health_check_path: string | null;
    push_to_deploy: boolean;
    connection: { name: string; provider: string; provider_label: string } | null;
    deploy_key: { public_key: string; fingerprint: string; installed: boolean; install_error: string | null } | null;
    laravel: LaravelToggles;
    shared_paths: SharedPathItem[];
    environment_version: number | null;
    deploy_macros: string[];
    created_at: string;
}

interface Props {
    site: SiteHeader;
    details: Details;
    targets: SiteTarget[];
    recentCommands: { id: string; command: string; server_name: string; status: CommandStatus; created_at: string }[];
    warnings: string[];
    can: { update: boolean; runCommands: boolean };
}

export default function Show({ site, details, targets, recentCommands, warnings, can }: Props) {
    const missingActivate = !details.deploy_macros.includes('KILN_ACTIVATE');

    return (
        <SiteLayout site={site} title="Overview" actions={<SiteStatusBadge status={details.status} />}>
            <Warnings warnings={warnings} />

            {details.laravel.maintenance && (
                <div className="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-sm">
                    <AlertTriangle className="size-4 text-amber-600" /> Maintenance mode is on.
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <TargetsCard siteId={site.id} targets={targets} canUpdate={can.update} />

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div>
                                <CardTitle>Recent commands</CardTitle>
                                <CardDescription>Commands run in the current release.</CardDescription>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/sites/${site.id}/commands`}>
                                    <Terminal /> {can.runCommands ? 'Run command' : 'History'}
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {recentCommands.length === 0 && <p className="text-muted-foreground text-sm">No commands yet.</p>}
                            {recentCommands.map((command) => (
                                <div key={command.id} className="flex items-center gap-3 text-sm">
                                    <code className="min-w-0 flex-1 truncate">{command.command}</code>
                                    <span className="text-muted-foreground hidden text-xs sm:inline">{command.server_name}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {formatDistanceToNow(new Date(command.created_at), { addSuffix: true })}
                                    </span>
                                    <CommandStatusBadge status={command.status} />
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y">
                                <DefinitionRow label="Runtime">
                                    {details.runtime_label}
                                    {details.php_version && ` · PHP ${details.php_version}`}
                                    {details.node_version && !details.php_version && ` · Node ${details.node_version}`}
                                </DefinitionRow>
                                <DefinitionRow label="Build">{details.build_mode}</DefinitionRow>
                                <DefinitionRow label="Root">
                                    <code className="text-xs">{details.root_path}</code>
                                </DefinitionRow>
                                {(details.runtime === 'frankenphp' || details.runtime === 'php-fpm' || details.runtime === 'static') && (
                                    <DefinitionRow label="Document root">
                                        <code className="text-xs">{details.document_root}</code>
                                    </DefinitionRow>
                                )}
                                {details.app_port && <DefinitionRow label="App port">127.0.0.1:{details.app_port}</DefinitionRow>}
                                {details.docker_image && <DefinitionRow label="Image">{details.docker_image}</DefinitionRow>}
                                {details.dockerfile && <DefinitionRow label="Dockerfile">{details.dockerfile}</DefinitionRow>}
                                {details.compose_file && <DefinitionRow label="Compose">{details.compose_file}</DefinitionRow>}
                                <DefinitionRow label="Linux user">
                                    {details.unix_user}
                                    {details.isolated && (
                                        <Badge variant="secondary" className="ml-2">
                                            isolated
                                        </Badge>
                                    )}
                                </DefinitionRow>
                                {details.health_check_path && <DefinitionRow label="Health check">{details.health_check_path}</DefinitionRow>}
                                <DefinitionRow label="Environment">
                                    <Link href={`/sites/${site.id}/environment`} className="underline">
                                        {details.environment_version ? `Version ${details.environment_version}` : 'Not set'}
                                    </Link>
                                </DefinitionRow>
                                <DefinitionRow label="Deploy script">
                                    <Link href={`/sites/${site.id}/deploy-script`} className="underline">
                                        Edit
                                    </Link>
                                    {missingActivate && <span className="ml-2 text-xs text-amber-600">no $KILN_ACTIVATE</span>}
                                </DefinitionRow>
                                {details.shared_paths.length > 0 && (
                                    <DefinitionRow label="Shared">
                                        <span className="font-mono text-xs">{details.shared_paths.map((path) => path.path).join(', ')}</span>
                                    </DefinitionRow>
                                )}
                                {details.is_laravel && (
                                    <DefinitionRow label="Laravel">
                                        {(['scheduler', 'horizon', 'octane'] as const).filter((key) => details.laravel[key]).join(', ') ||
                                            'No extras'}
                                    </DefinitionRow>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Repository</CardTitle>
                            <CardDescription>
                                {details.connection
                                    ? `${details.connection.provider_label} · ${details.connection.name}`
                                    : site.repository
                                      ? 'Connection removed'
                                      : 'Not connected'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {site.repository ? (
                                <p className="font-mono text-xs break-all">
                                    {site.repository}@{site.branch}
                                </p>
                            ) : (
                                <p className="text-muted-foreground">
                                    Connect a repository in{' '}
                                    <Link href={`/sites/${site.id}/settings`} className="underline">
                                        settings
                                    </Link>
                                    .
                                </p>
                            )}
                            {site.repository && (
                                <p className="text-muted-foreground text-xs">Push to deploy: {details.push_to_deploy ? 'on' : 'off'}</p>
                            )}
                            {details.deploy_key && (
                                <div className="space-y-2 rounded-md border p-2">
                                    <div className="flex items-center gap-2 text-xs font-medium">
                                        <KeyRound className="size-3.5" /> Deploy key
                                        <span className="text-muted-foreground font-normal">{details.deploy_key.fingerprint}</span>
                                        <span className="ml-auto">
                                            <CopyButton value={details.deploy_key.public_key} label="Copy public key" />
                                        </span>
                                    </div>
                                    {details.deploy_key.installed ? (
                                        <p className="text-xs text-emerald-600">Added to the repository as a read-only key.</p>
                                    ) : (
                                        <>
                                            <p className="text-xs text-amber-600">
                                                Add this public key to the repository as a read-only deploy key
                                                {details.deploy_key.install_error ? ` (${details.deploy_key.install_error})` : ''}.
                                            </p>
                                            <code className="bg-muted block rounded p-2 text-[11px] break-all">{details.deploy_key.public_key}</code>
                                        </>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </SiteLayout>
    );
}
