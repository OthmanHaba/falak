import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import SiteLayout from '@/layouts/site-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { Rocket, X } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';
import { between, Commit, DeploymentStatusBadge, StepIcon, when } from '../components/deploy-ui';
import { type Deployment, type Release, type SitePageProps, type Target } from '../types';

interface Props extends SitePageProps {
    active: (Deployment & { targets: Target[] }) | null;
    queued: Deployment[];
    history: { data: Deployment[]; links: { prev: string | null; next: string | null } };
    current: Release | null;
    strategy: string;
    defaultBranch: string | null;
    canDeploy: boolean;
    can: { create: boolean; cancel: boolean };
}

export default function Index({ site, active, queued, history, current, strategy, defaultBranch, canDeploy, can }: Props) {
    const form = useForm({ branch: defaultBranch ?? '' });
    const reload = () => router.reload({ only: ['active', 'queued', 'history', 'current'] });
    const live = useEchoChannel(`deployments.site.${site.id}`, ['deployment.updated'], reload);

    useEffect(() => {
        if (live || (!active && queued.length === 0)) {
            return;
        }

        const timer = window.setInterval(reload, 3000);

        return () => window.clearInterval(timer);
    }, [live, active, queued.length]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(`/sites/${site.id}/deployments`);
    };

    const cancel = (deployment: Deployment) => router.post(`/sites/${site.id}/deployments/${deployment.id}/cancel`, {}, { preserveScroll: true });

    return (
        <SiteLayout site={site} title="Deployments">
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Deploy</CardTitle>
                        <CardDescription>
                            {strategy} strategy · current release{' '}
                            {current ? (
                                <>
                                    <span className="font-mono">{current.id.toUpperCase().slice(-8)}</span> (
                                    <Commit sha={current.commit} branch={current.branch} />)
                                </>
                            ) : (
                                'none yet'
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {can.create && canDeploy ? (
                            <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                                <div className="grid gap-1">
                                    <label htmlFor="branch" className="text-muted-foreground text-xs">
                                        Branch
                                    </label>
                                    <Input
                                        id="branch"
                                        value={form.data.branch}
                                        onChange={(e) => form.setData('branch', e.target.value)}
                                        className="w-56 font-mono"
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    <Rocket className="size-4" /> Deploy now
                                </Button>
                                {form.errors.branch && <p className="w-full text-sm text-red-600">{form.errors.branch}</p>}
                            </form>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {canDeploy ? 'You cannot deploy this site.' : 'Connect a repository in the site settings to deploy.'}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Queue</CardTitle>
                        <CardDescription>One deployment runs at a time; later ones wait here.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {active ? (
                            <Link href={`/sites/${site.id}/deployments/${active.id}`} className="hover:bg-muted/40 block rounded-md border p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">#{active.number}</span>
                                    <DeploymentStatusBadge status={active.status} />
                                </div>
                                <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                    <Commit sha={active.commit} branch={active.branch} /> · {active.phase ?? 'starting'} ·{' '}
                                    {between(active.started_at, null)}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-3">
                                    {active.targets.map((target) => (
                                        <span key={target.id} className="flex items-center gap-1 text-xs">
                                            <StepIcon
                                                status={
                                                    target.status === 'succeeded'
                                                        ? 'succeeded'
                                                        : target.status === 'failed'
                                                          ? 'failed'
                                                          : target.status === 'pending'
                                                            ? 'pending'
                                                            : 'running'
                                                }
                                            />
                                            {target.server_name}
                                        </span>
                                    ))}
                                </div>
                            </Link>
                        ) : (
                            <p className="text-muted-foreground">Nothing deploying.</p>
                        )}
                        {queued.map((deployment, index) => (
                            <div
                                key={deployment.id}
                                className="ml-2 flex items-center justify-between rounded-md border border-dashed p-2"
                                style={{ marginLeft: `${(index + 1) * 6}px` }}
                            >
                                <Link href={`/sites/${site.id}/deployments/${deployment.id}`} className="text-xs">
                                    #{deployment.number} · <Commit sha={deployment.commit} branch={deployment.branch} /> · {deployment.trigger}
                                </Link>
                                {can.cancel && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => cancel(deployment)}
                                        aria-label={`Cancel deployment ${deployment.number}`}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>History</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Commit</TableHead>
                                <TableHead>Trigger</TableHead>
                                <TableHead>Started</TableHead>
                                <TableHead>Duration</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {history.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-muted-foreground text-center">
                                        No deployments yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {history.data.map((deployment) => (
                                <TableRow
                                    key={deployment.id}
                                    className="cursor-pointer"
                                    onClick={() => router.visit(`/sites/${site.id}/deployments/${deployment.id}`)}
                                >
                                    <TableCell className="font-medium">{deployment.number}</TableCell>
                                    <TableCell>
                                        <DeploymentStatusBadge status={deployment.status} rolledBack={deployment.rolled_back} />
                                    </TableCell>
                                    <TableCell className="max-w-72 truncate">
                                        <Commit sha={deployment.commit} branch={deployment.branch} />{' '}
                                        <span className="text-muted-foreground">{deployment.message?.split('\n')[0]}</span>
                                    </TableCell>
                                    <TableCell className="capitalize">{deployment.trigger}</TableCell>
                                    <TableCell>{when(deployment.started_at ?? deployment.created_at)}</TableCell>
                                    <TableCell>{between(deployment.started_at, deployment.finished_at)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="mt-4 flex justify-end gap-2">
                        {history.links.prev && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={history.links.prev}>Newer</Link>
                            </Button>
                        )}
                        {history.links.next && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={history.links.next}>Older</Link>
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>
        </SiteLayout>
    );
}
