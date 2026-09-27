import { Avatar, Menu, RelativeTime, StatusDot, Tag, formatDuration, type MenuAction } from '@/components/kiln';
import { cn } from '@/lib/utils';
import { GitCommitHorizontal } from 'lucide-react';
import { type ReactNode } from 'react';
import { type Deployment } from '../types';
import { durationMs, firstLine } from './api';

export function CommitTag({ sha, branch }: { sha: string | null; branch?: string | null }) {
    return (
        <span className="text-fg-muted inline-flex min-w-0 items-center gap-1 font-mono text-xs">
            <GitCommitHorizontal className="text-fg-faint size-3.5 shrink-0" aria-hidden />
            {branch && <span className="truncate">{branch}@</span>}
            <span>{sha ? sha.slice(0, 7) : 'HEAD'}</span>
        </span>
    );
}

const TRIGGERS: Record<string, string> = {
    manual: 'Manual',
    push: 'Push',
    hook: 'Deploy hook',
    api: 'API',
    rollback: 'Rollback',
    schedule: 'Scheduled',
};

export function triggerLabel(trigger: string): string {
    return TRIGGERS[trigger] ?? trigger.charAt(0).toUpperCase() + trigger.slice(1);
}

/** One deployment in the history list: status, message, commit, author, age, duration; click opens the Deploy view. */
export function DeploymentRow({
    deployment,
    onOpen,
    actions = [],
    badge,
    className,
}: {
    deployment: Deployment;
    onOpen: () => void;
    actions?: MenuAction[];
    badge?: ReactNode;
    className?: string;
}) {
    const duration = durationMs(deployment.started_at, deployment.finished_at);

    return (
        <div className={cn('group hover:bg-surface-2 relative flex items-center gap-3 rounded-md px-3 py-2.5', className)}>
            <StatusDot status={deployment.status} label={deployment.status} />
            <button type="button" onClick={onOpen} className="grid min-w-0 flex-1 gap-0.5 text-left after:absolute after:inset-0 after:rounded-md">
                <span className="flex min-w-0 items-center gap-2">
                    <span className="text-fg-faint tabular shrink-0 text-xs">#{deployment.number}</span>
                    <span className="text-fg truncate text-sm">{firstLine(deployment.message) ?? triggerLabel(deployment.trigger)}</span>
                    {badge}
                    {deployment.rolled_back && <Tag tone="warning">rolled back</Tag>}
                </span>
                <span className="text-fg-faint flex min-w-0 items-center gap-2 text-xs">
                    <CommitTag sha={deployment.commit} branch={deployment.branch} />
                    <span className="hidden sm:inline">·</span>
                    <span className="hidden sm:inline">{triggerLabel(deployment.trigger)}</span>
                </span>
            </button>
            <span className="hidden shrink-0 items-center gap-2 md:flex">
                {deployment.author && <Avatar name={deployment.author} size="xs" />}
                <span className="text-fg-muted max-w-28 truncate text-xs">{deployment.author}</span>
            </span>
            <span className="text-fg-faint grid shrink-0 justify-items-end text-xs">
                <RelativeTime value={deployment.created_at} />
                <span className="tabular">{duration !== null && deployment.finished_at ? formatDuration(duration) : ''}</span>
            </span>
            {actions.length > 0 && (
                <span className="relative z-10">
                    <Menu actions={actions} label={`Deployment #${deployment.number} actions`} />
                </span>
            )}
        </div>
    );
}
