import { formatDuration } from '@/components/kiln';
import { cn } from '@/lib/utils';
import { Hourglass } from 'lucide-react';
import { type Deployment } from '../types';
import { durationMs } from './api';

/**
 * A deployment held until the site's servers finish preparing: the reason ("Waiting for 2 servers to finish
 * preparing: web-1, web-2") and how long it has waited. It starts on its own; the deploy view offers Cancel.
 */
export function WaitingNotice({
    deployment,
    compact = false,
}: {
    deployment: Pick<Deployment, 'waiting_reason' | 'waiting_since'>;
    compact?: boolean;
}) {
    const waited = durationMs(deployment.waiting_since, null);

    return (
        <div
            role="status"
            data-testid="waiting-notice"
            className={cn('border-info/30 bg-info-soft flex items-start gap-2.5 rounded-lg border px-3', compact ? 'py-2' : 'py-2.5')}
        >
            <Hourglass className="text-info mt-0.5 size-4 shrink-0 animate-pulse" aria-hidden />
            <div className="grid min-w-0 flex-1 gap-0.5 text-sm">
                <span className="text-fg font-medium">{deployment.waiting_reason ?? "Waiting for the site's servers to finish preparing"}</span>
                {!compact && (
                    <span className="text-fg-muted">
                        The deployment starts automatically once they are ready. New pushes and deploys update it instead of queueing another.
                    </span>
                )}
            </div>
            {waited !== null && <span className="text-fg-faint tabular shrink-0 text-xs">{formatDuration(waited)}</span>}
        </div>
    );
}
