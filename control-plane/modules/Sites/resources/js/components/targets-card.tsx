import { CommandLog } from '@/components/command-log';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { router } from '@inertiajs/react';
import { Crown, RotateCw } from 'lucide-react';
import { useState } from 'react';
import { type SiteTarget } from '../types';
import { TargetStatusBadge } from './site-ui';

/**
 * The site's servers with their preparation status; failed targets can be retried and a running step shows live output.
 */
export function TargetsCard({ siteId, targets, canUpdate }: { siteId: string; targets: SiteTarget[]; canUpdate: boolean }) {
    const [open, setOpen] = useState<string | null>(null);
    const busy = targets.some((target) => target.status === 'provisioning' || target.status === 'pending');

    // Refresh when a preparation step finishes (the command channel of the running step).
    const running = targets.find((target) => target.command_id)?.command_id ?? null;
    useEchoChannel<{ status: string }>(running ? `fleet.commands.${running}` : null, ['command.output'], (_event, payload) => {
        if (['succeeded', 'failed', 'timed_out', 'cancelled'].includes(payload.status)) {
            router.reload({ only: ['targets', 'details'] });
        }
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Servers</CardTitle>
                <CardDescription>
                    Deploys go to every server{targets.length > 1 ? '; the leader runs migrations and the scheduler' : ''}.
                    {busy && ' Preparing site users and PHP pools…'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
                {targets.length === 0 && <p className="text-muted-foreground text-sm">This site has no servers.</p>}
                {targets.map((target) => (
                    <div key={target.id} className="rounded-lg border p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{target.server_name}</span>
                            {target.role === 'leader' && (
                                <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                    <Crown className="size-3" /> leader
                                </span>
                            )}
                            <span className="text-muted-foreground text-xs">{target.server_ip}</span>
                            <span className="ml-auto flex items-center gap-2">
                                <TargetStatusBadge status={target.status} />
                                {target.command_id && (
                                    <Button variant="ghost" size="sm" onClick={() => setOpen(open === target.id ? null : target.id)}>
                                        {open === target.id ? 'Hide output' : 'Output'}
                                    </Button>
                                )}
                                {canUpdate && target.status === 'failed' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.post(`/sites/${siteId}/targets/${target.id}/retry`, {}, { preserveScroll: true })}
                                    >
                                        <RotateCw /> Retry
                                    </Button>
                                )}
                            </span>
                        </div>
                        {target.status_message && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{target.status_message}</p>}
                        {open === target.id && target.command_id && <CommandLog commandId={target.command_id} className="mt-3" />}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
