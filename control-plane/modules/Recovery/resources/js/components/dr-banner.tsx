import { Button } from '@/components/falak';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import { useState } from 'react';
import { disasterRecoveryOf } from '../types';

/**
 * Above every page for the install's owners and admins until the control plane's disaster recovery is configured
 * (falak-ctl dr setup). "Remind me later" hides it for 30 days (server-side, per user); then it comes back.
 */
export function DisasterRecoveryBanner() {
    const { props } = usePage<SharedData>();
    const dr = disasterRecoveryOf(props);
    const [hiding, setHiding] = useState(false);

    if (!dr?.banner || hiding) return null;

    return (
        <div role="status" className="border-warning/30 bg-warning-soft text-fg border-b px-4 py-2 text-sm">
            <div className="mx-auto flex max-w-[1200px] flex-wrap items-center gap-x-3 gap-y-2">
                <LifeBuoy className="text-warning size-4 shrink-0" aria-hidden />
                <p className="mr-auto min-w-0">
                    <span className="font-medium">Disaster recovery is not set up.</span>{' '}
                    <span className="text-fg-muted">
                        If this host is lost, so are the Fleet CA, the encryption keys and every setting. Send encrypted backups off this host.
                    </span>
                </p>
                <Button asChild size="sm" variant="primary">
                    <Link href={dr.settings_url}>Set up</Link>
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        setHiding(true);
                        router.post('/settings/disaster-recovery/dismiss', {}, { preserveScroll: true, preserveState: true });
                    }}
                >
                    Remind me in 30 days
                </Button>
            </div>
        </div>
    );
}
