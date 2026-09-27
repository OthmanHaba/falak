import { Avatar, Button, EmptyState, Menu, RelativeTime, SkeletonRows, StatusBadge, Tag, formatDuration, type MenuAction } from '@/components/kiln';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { useJson } from '@/hooks/use-json';
import { requestJson } from '@/lib/http';
import { type ServicePanelContext, type ServiceTabProps } from '@/lib/registry';
import { Ban, History, Rocket, RotateCcw, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { type Deployment } from '../types';
import { cancelDeployment, deploy, deploymentsUrl, durationMs, firstLine, rollback, type DeploymentsOverview, type RunningDeployment } from './api';
import { DeployView } from './deploy-view';
import { CommitTag, DeploymentRow, triggerLabel } from './deployment-row';
import { WaitingNotice } from './waiting-notice';

function rowActions(ctx: ServicePanelContext, overview: DeploymentsOverview, deployment: Deployment, reload?: () => void): MenuAction[] {
    const actions: MenuAction[] = [{ label: 'View logs', icon: <ScrollText />, onSelect: () => ctx.open('deployments', deployment.id) }];

    if (overview.can.cancel && (deployment.status === 'waiting' || deployment.status === 'queued')) {
        actions.push({
            label: 'Cancel',
            icon: <Ban />,
            onSelect: () => void cancelDeployment(ctx, deployment).then((ok) => ok && reload?.()),
        });
    }

    if (overview.can.create && deployment.commit) {
        actions.push({
            label: 'Redeploy this commit',
            icon: <Rocket />,
            onSelect: () => void deploy(ctx, { commit: deployment.commit, branch: deployment.branch }),
        });
    }
    if (overview.can.rollback && deployment.release_id && deployment.status === 'succeeded' && deployment.release_id !== overview.current?.id) {
        actions.push({ label: 'Rollback to this release', icon: <RotateCcw />, onSelect: () => void rollback(ctx, deployment.release_id!) });
    }

    return actions;
}

/** Big card on top: the deployment running now, or the one whose release is live. */
function FeaturedDeployment({
    ctx,
    deployment,
    label,
    live,
    actions,
}: {
    ctx: ServicePanelContext;
    deployment: Deployment | RunningDeployment;
    label: string;
    live: boolean;
    actions: MenuAction[];
}) {
    const waiting = live && deployment.status === 'waiting';
    const duration = durationMs(deployment.started_at, deployment.finished_at);
    const servers =
        'targets' in deployment && deployment.targets.length > 0
            ? deployment.targets.map((target) => ({ id: target.server_id, name: target.server_name, leader: target.role === 'leader' }))
            : ctx.service.servers;

    return (
        <section aria-label={label} className="border-border bg-surface-1 grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={live ? deployment.status : 'active'} label={waiting ? 'Waiting' : live ? undefined : 'Active'} />
                <span className="text-fg-faint text-xs">{label}</span>
                <span className="text-fg-faint tabular ml-auto text-xs">#{deployment.number}</span>
                {actions.length > 0 && <Menu actions={actions} label={`Deployment #${deployment.number} actions`} />}
            </div>
            <div className="grid gap-1">
                <p className="text-fg text-sm font-medium">{firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}</p>
                <div className="text-fg-muted flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <CommitTag sha={deployment.commit} branch={deployment.branch} />
                    {deployment.author && (
                        <span className="flex items-center gap-1.5">
                            <Avatar name={deployment.author} size="xs" />
                            {deployment.author}
                        </span>
                    )}
                    <RelativeTime value={deployment.finished_at ?? deployment.started_at ?? deployment.created_at} />
                    {duration !== null && (
                        <span className="tabular">
                            {live && !deployment.finished_at ? 'running ' : ''}
                            {formatDuration(duration)}
                        </span>
                    )}
                    <span>{triggerLabel(deployment.trigger)}</span>
                </div>
            </div>
            {waiting && <WaitingNotice deployment={deployment} compact />}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap gap-1">
                    {servers.map((server) => (
                        <Tag key={server.id} mono>
                            {server.name}
                            {server.leader && servers.length > 1 ? ' ★' : ''}
                        </Tag>
                    ))}
                </div>
                <Button size="sm" icon={<ScrollText />} onClick={() => ctx.open('deployments', deployment.id)}>
                    View logs
                </Button>
            </div>
        </section>
    );
}

function DeploymentList({ ctx }: { ctx: ServicePanelContext }) {
    const siteId = ctx.service.ref_id;
    const [interval, setIntervalMs] = useState(15000);
    const { data, error, reload } = useJson<DeploymentsOverview>(deploymentsUrl(siteId), { interval });
    const [more, setMore] = useState<{ items: Deployment[]; next: string | null } | null>(null);
    const [loadingMore, setLoadingMore] = useState(false);

    const live = useEchoChannel(`deployments.site.${siteId}`, ['deployment.updated'], () => {
        void reload();
        ctx.refresh();
    });

    const busy = Boolean(data?.active || (data?.queued.length ?? 0) > 0);
    const wanted = busy ? (live ? 10000 : 3000) : live ? 60000 : 15000;
    if (wanted !== interval) setIntervalMs(wanted);

    if (!data) {
        return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;
    }

    const history = [...data.history.data, ...(more?.items ?? [])];
    const next = more ? more.next : data.history.links.next;
    const liveDeployment = data.current ? history.find((item) => item.id === data.current?.deployment_id) : undefined;
    const rest = history.filter((item) => item.id !== liveDeployment?.id);

    if (!data.active && data.queued.length === 0 && history.length === 0) {
        return (
            <EmptyState
                icon={<Rocket />}
                title="No deployments yet"
                description={
                    data.canDeploy
                        ? `Deploy ${data.defaultBranch ? `the ${data.defaultBranch} branch` : 'the service'} to its servers. Every deployment builds, releases and health-checks with zero downtime.`
                        : 'Connect a repository or a Docker image in Settings, then deploy it here.'
                }
                action={
                    data.can.create &&
                    data.canDeploy && (
                        <Button variant="primary" icon={<Rocket />} onClick={() => void deploy(ctx)}>
                            Deploy now
                        </Button>
                    )
                }
            />
        );
    }

    const loadMore = async () => {
        if (!next) return;
        setLoadingMore(true);
        try {
            const body = await requestJson<{ data: DeploymentsOverview }>(next);
            setMore({ items: [...(more?.items ?? []), ...body.data.history.data], next: body.data.history.links.next });
        } finally {
            setLoadingMore(false);
        }
    };

    return (
        <div className="grid gap-5">
            {data.active && (
                <FeaturedDeployment
                    ctx={ctx}
                    deployment={data.active}
                    label={data.active.status === 'waiting' ? 'Waiting for servers' : 'In progress'}
                    live
                    actions={rowActions(ctx, data, data.active, () => void reload())}
                />
            )}

            {data.queued.length > 0 && (
                <section aria-label="Queued deployments" className="grid gap-1">
                    <h3 className="text-fg-faint text-2xs px-1 font-medium tracking-wide uppercase">
                        Queued · runs after the {data.active?.status === 'waiting' ? 'waiting' : 'current'} deployment
                    </h3>
                    <div className="border-border grid rounded-lg border border-dashed p-1">
                        {data.queued.map((deployment) => (
                            <DeploymentRow
                                key={deployment.id}
                                deployment={deployment}
                                onOpen={() => ctx.open('deployments', deployment.id)}
                                actions={rowActions(ctx, data, deployment, () => void reload())}
                            />
                        ))}
                    </div>
                </section>
            )}

            {liveDeployment && (
                <FeaturedDeployment
                    ctx={ctx}
                    deployment={liveDeployment}
                    label={`Live since ${new Date(data.current?.activated_at ?? liveDeployment.created_at).toLocaleString()}`}
                    live={false}
                    actions={rowActions(ctx, data, liveDeployment)}
                />
            )}

            {rest.length > 0 && (
                <section aria-label="Deployment history" className="grid gap-1">
                    <h3 className="text-fg-faint text-2xs flex items-center gap-1.5 px-1 font-medium tracking-wide uppercase">
                        <History className="size-3" aria-hidden /> History
                    </h3>
                    <div className="grid">
                        {rest.map((deployment) => (
                            <DeploymentRow
                                key={deployment.id}
                                deployment={deployment}
                                onOpen={() => ctx.open('deployments', deployment.id)}
                                actions={rowActions(ctx, data, deployment)}
                            />
                        ))}
                    </div>
                    {next && (
                        <Button variant="ghost" size="sm" className="justify-self-center" loading={loadingMore} onClick={() => void loadMore()}>
                            Load older deployments
                        </Button>
                    )}
                </section>
            )}
        </div>
    );
}

/** §5.1 Deployments tab: active deployment on top, queued stack, history; an item opens the §5.2 Deploy view. */
export function DeploymentsTab({ ctx }: ServiceTabProps) {
    return ctx.item ? <DeployView key={ctx.item} ctx={ctx} deploymentId={ctx.item} /> : <DeploymentList ctx={ctx} />;
}
