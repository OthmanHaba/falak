import { Stat } from '@/components/kiln/stat';
import ObservabilityLayout from '@/layouts/observability-layout';
import { HeartbeatTable, monitorState } from '../components/heartbeat-table';
import { type HeartbeatRow } from '../types';

interface Props {
    monitors: HeartbeatRow[];
    defaultGraceSeconds: number;
    can: { manage: boolean };
}

export default function Heartbeats({ monitors, defaultGraceSeconds, can }: Props) {
    const enabled = monitors.filter((monitor) => monitor.enabled);
    const missed = enabled.filter((monitor) => monitor.missed_at).length;
    const failing = enabled.filter((monitor) => monitorState(monitor).status === 'failed').length - missed;
    const expected = enabled.reduce((sum, monitor) => sum + (monitor.expected_24h ?? 0), 0);
    const missedRuns = enabled.reduce((sum, monitor) => sum + (monitor.missed_24h ?? 0), 0);

    return (
        <ObservabilityLayout tab="heartbeats">
            {monitors.length > 0 && (
                <section aria-label="Summary" className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Stat label="Monitored tasks" value={enabled.length} hint={`${monitors.length - enabled.length} paused`} />
                    <Stat label="Missing" value={missed} tone={missed > 0 ? 'danger' : undefined} hint={`grace ${defaultGraceSeconds}s by default`} />
                    <Stat label="Failing" value={failing} tone={failing > 0 ? 'danger' : undefined} hint="last run failed or timed out" />
                    <Stat
                        label="Runs · 24h"
                        value={expected > 0 ? `${expected - missedRuns}/${expected}` : '—'}
                        tone={missedRuns > 0 ? 'warning' : undefined}
                        hint={missedRuns > 0 ? `${missedRuns} expected runs missed` : 'every expected run reported'}
                    />
                </section>
            )}
            <HeartbeatTable monitors={monitors} canManage={can.manage} defaultGrace={defaultGraceSeconds} showSite />
        </ObservabilityLayout>
    );
}
