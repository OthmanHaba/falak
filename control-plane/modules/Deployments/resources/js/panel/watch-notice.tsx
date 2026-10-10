import { Callout, formatDuration } from '@/components/falak';
import { cn } from '@/lib/utils';
import { Eye } from 'lucide-react';
import { type ReactNode } from 'react';
import { type Deployment, type ReleaseWatch, type WatchTrigger } from '../types';

const percent = (rate: number | undefined) => (rate === undefined ? '—' : `${(rate * 100).toFixed(1)}%`);

/** One trigger's live status: "Health check · 1/3 failures". */
function TriggerRow({ label, state, children }: { label: string; state: 'ok' | 'warn' | 'off' | 'unknown'; children: ReactNode }) {
    return (
        <li className="flex min-w-0 items-baseline gap-2 text-sm">
            <span
                aria-hidden
                className={cn(
                    'mt-1.5 size-1.5 shrink-0 rounded-full',
                    state === 'ok' && 'bg-success',
                    state === 'warn' && 'bg-warning',
                    state === 'off' && 'bg-fg-faint/40',
                    state === 'unknown' && 'bg-fg-faint',
                )}
            />
            <span className="text-fg shrink-0 font-medium">{label}</span>
            <span className="text-fg-muted min-w-0 truncate" title={typeof children === 'string' ? children : undefined}>
                {children}
            </span>
        </li>
    );
}

const TRIGGER_LABELS: Record<WatchTrigger, string> = {
    health: 'Health check',
    crash: 'Crash or OOM kill',
    errors: '5xx rate',
    issue: 'New error',
};

function Triggers({ watch }: { watch: ReleaseWatch }) {
    const { triggers, checks } = watch;
    const health = checks.health;
    const errors = checks.errors;

    return (
        <ul className="grid gap-1" aria-label="Triggers">
            <TriggerRow
                label="Health check"
                state={health?.unavailable ? 'unknown' : !triggers.health ? 'off' : !health ? 'unknown' : (health.failures ?? 0) > 0 ? 'warn' : 'ok'}
            >
                {health?.unavailable
                    ? health.unavailable
                    : !triggers.health
                      ? 'off'
                      : health
                        ? `${health.failures ?? 0}/${health.threshold ?? 0} failures in a row${health.message ? ` · ${health.message}` : ''}`
                        : 'first check within 30 s'}
            </TriggerRow>
            <TriggerRow label="5xx rate" state={!triggers.errors ? 'off' : !errors || errors.unavailable ? 'unknown' : 'ok'}>
                {!triggers.errors
                    ? 'off'
                    : !errors
                      ? 'first count within 30 s'
                      : errors.unavailable
                        ? errors.unavailable
                        : `${percent(errors.rate)} of ${errors.total ?? 0} requests (limit ${percent(errors.threshold)}, from ${errors.min_requests} requests)`}
            </TriggerRow>
            <TriggerRow label="Crashes" state={triggers.crashes ? 'ok' : 'off'}>
                {triggers.crashes ? 'no OOM kill or restart loop' : 'off'}
            </TriggerRow>
            <TriggerRow label="New errors" state={triggers.issues ? 'ok' : 'off'}>
                {triggers.issues ? 'no new error in Insights' : 'off'}
            </TriggerRow>
        </ul>
    );
}

/**
 * The watch after a release went live: "Watching for 4 more minutes" with each trigger's status, or how it ended — rolled
 * back (with the reason), alerted only, or passed.
 */
export function WatchNotice({ deployment, onOpen }: { deployment: Deployment; onOpen?: (deploymentId: string) => void }) {
    const watch = deployment.watch;

    if (!watch) return null;

    const migrations = watch.migrations ? ' This deployment ran database migrations; a rollback does not reverse them.' : '';

    if (watch.status === 'watching') {
        const remaining = Math.max(0, new Date(watch.ends_at).getTime() - Date.now());

        return (
            <section role="status" data-testid="watch-notice" className="border-info/30 bg-info-soft grid gap-2.5 rounded-lg border px-3.5 py-3">
                <div className="flex items-start gap-2.5">
                    <Eye className="text-info mt-0.5 size-4 shrink-0 animate-pulse" aria-hidden />
                    <div className="grid min-w-0 flex-1 gap-0.5 text-sm">
                        <span className="text-fg font-medium">
                            Watching the release for {remaining > 0 ? `${formatDuration(remaining)} more` : 'a last check'}
                        </span>
                        <span className="text-fg-muted">
                            {watch.on_trigger === 'alert_only'
                                ? 'If a trigger fires, an alert goes out and the release stays live.'
                                : 'If a trigger fires, the site rolls back to the previous release.'}
                            {migrations}
                        </span>
                    </div>
                </div>
                <div className="pl-6.5">
                    <Triggers watch={watch} />
                </div>
            </section>
        );
    }

    const what = watch.trigger ? TRIGGER_LABELS[watch.trigger] : null;

    if (watch.status === 'rolled_back') {
        return (
            <Callout
                tone="warning"
                title={`Rolled back automatically${what ? ` · ${what}` : ''}`}
                action={
                    watch.rollback_deployment_id && onOpen ? (
                        <button type="button" className="text-fg text-xs font-medium underline" onClick={() => onOpen(watch.rollback_deployment_id!)}>
                            View rollback
                        </button>
                    ) : undefined
                }
            >
                {watch.reason}
                {migrations}
            </Callout>
        );
    }

    if (watch.status === 'alerted') {
        return (
            <Callout tone="danger" title={`Unhealthy after going live${what ? ` · ${what}` : ''} — not rolled back`}>
                {watch.reason}
                {watch.on_trigger === 'alert_only' ? ' The site is set to alert only.' : ''}
                {migrations}
            </Callout>
        );
    }

    if (watch.status === 'passed') {
        return (
            <p className="text-fg-muted flex items-center gap-2 text-xs" data-testid="watch-notice">
                <Eye className="size-3.5" aria-hidden />
                Watched for {formatDuration(new Date(watch.ends_at).getTime() - new Date(watch.started_at).getTime())} after going live: no trigger
                fired.
            </p>
        );
    }

    return null;
}
