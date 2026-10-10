import { Button, Callout, CodeBlock, KeyValue, RelativeTime, Section, StatusBadge } from '@/components/falak';
import SettingsLayout from '@/layouts/settings/layout';
import { router } from '@inertiajs/react';
import { bytesLabel, type ControlPlaneStatus } from '../types';

interface Props {
    status: ControlPlaneStatus;
    dismissed_until: string | null;
    commands: { setup: string; status: string; backup: string; drill: string; restore: string };
}

/**
 * Settings → Disaster recovery (the install's owners and admins): the control plane's backups as falak-ctl reports
 * them. Configuration happens on the host with falak-ctl; the panel never sees the bucket keys or the passphrase.
 */
export default function Settings({ status, dismissed_until, commands }: Props) {
    const backup = status.last_backup;
    const drill = status.last_drill;

    return (
        <SettingsLayout
            title="Disaster recovery"
            description="Encrypted backups of the control plane itself, off this host, on a schedule, proven by restore drills."
        >
            {!status.available && (
                <Callout tone="warning" title="No status from falak-ctl yet">
                    The control plane's host has not reported its disaster recovery (state/dr.json). Run <code>falak-ctl up</code> or{' '}
                    <code>falak-ctl dr status</code> on it.
                </Callout>
            )}

            {status.available && !status.configured && (
                <Callout
                    tone="warning"
                    title="Not configured"
                    action={
                        !dismissed_until && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post('/settings/disaster-recovery/dismiss', {}, { preserveScroll: true })}
                            >
                                Remind me in 30 days
                            </Button>
                        )
                    }
                >
                    Backups stay on the control plane's own host. If it is lost, so are the Fleet CA (every agent would need re-enrolling), the
                    key-encryption key and every setting. Run the setup on the host: it asks for an S3 bucket and a DR passphrase, and installs a
                    backup timer.
                </Callout>
            )}

            {status.backup_failing && status.last_failure && (
                <Callout tone="danger" title="The last scheduled backup failed">
                    <RelativeTime value={status.last_failure.at} />: {status.last_failure.error ?? 'see journalctl -u falak-backup'}
                </Callout>
            )}
            {status.configured && status.backup_missing && (
                <Callout tone="danger" title="No recent backup">
                    Nothing in more than twice the {status.schedule_hours} h schedule. Check the timer on the host:{' '}
                    <code>systemctl status falak-backup.timer</code>.
                </Callout>
            )}
            {drill && !drill.ok && (
                <Callout tone="danger" title="The last restore drill failed">
                    {drill.message}
                </Callout>
            )}

            <Section title="Status" description="As falak-ctl last wrote it on the host.">
                <KeyValue
                    items={[
                        {
                            label: 'Configured',
                            value: <StatusBadge status={status.configured ? 'active' : 'inactive'} label={status.configured ? 'Yes' : 'No'} />,
                        },
                        { label: 'Destination', value: status.target ? `${status.target}${status.endpoint ? ` (${status.endpoint})` : ''}` : '—' },
                        {
                            label: 'Encryption',
                            value: status.encrypted ? 'AES-256, with the DR passphrase (the KEK is inside, wrapped by it)' : 'Off',
                        },
                        {
                            label: 'Schedule',
                            value: status.configured
                                ? `Every ${status.schedule_hours} h${status.include_registry ? ', registry included' : ''}`
                                : '—',
                        },
                        {
                            label: 'Last backup',
                            value: backup ? (
                                <span>
                                    <RelativeTime value={backup.at} /> · {bytesLabel(backup.size_bytes)} ·{' '}
                                    {backup.uploaded ? 'uploaded' : 'on this host only'}
                                    <span className="text-fg-faint block font-mono text-xs">{backup.name}</span>
                                </span>
                            ) : (
                                'None yet'
                            ),
                        },
                        {
                            label: 'Last drill',
                            value: drill ? (
                                <span>
                                    <StatusBadge status={drill.ok ? 'succeeded' : 'failed'} label={drill.ok ? 'Passed' : 'Failed'} />{' '}
                                    <RelativeTime value={drill.at} />
                                    {drill.duration_s !== null && ` · ${drill.duration_s} s`}
                                </span>
                            ) : (
                                'Never'
                            ),
                        },
                        { label: 'Drill schedule', value: status.drill_schedule === 'monthly' ? 'Monthly (falak-drill.timer)' : 'Off' },
                    ]}
                />
            </Section>

            <Section
                title="Configure on the host"
                description="Run these on the control plane's host as root. The bucket keys and the DR passphrase stay in /opt/falak/dr (root only); keep the passphrase in your password manager: without it no backup can be restored."
            >
                <div className="grid gap-3">
                    <CodeBlock title="Set up (S3 bucket, passphrase, schedule, monthly drill)" code={commands.setup} />
                    <CodeBlock title="Back up now" code={commands.backup} />
                    <CodeBlock title="Restore drill (a throwaway copy on other ports, then removed)" code={commands.drill} />
                    <CodeBlock title="Status" code={commands.status} />
                </div>
            </Section>

            <Section
                title="If this host is lost"
                description="On a fresh VPS with the same domain: the same CA and keys come back, and agents reconnect with their existing certificates once DNS points at it."
            >
                <CodeBlock code={commands.restore} wrap />
                <p className="text-fg-muted mt-2 text-sm">
                    It asks for the bucket settings and the DR passphrase. The runbook is docs/DISASTER_RECOVERY.md.
                </p>
            </Section>
        </SettingsLayout>
    );
}
