import Heading from '@/components/heading';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { HeartbeatTable } from '../components/heartbeat-table';
import { type HeartbeatMonitor } from '../types';

interface Props {
    monitors: HeartbeatMonitor[];
    defaultGraceSeconds: number;
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Insights', href: '/insights' },
    { title: 'Heartbeats', href: '/insights/heartbeats' },
];

export default function Heartbeats({ monitors, defaultGraceSeconds, can }: Props) {
    const unhealthy = monitors.filter((m) => m.enabled && !m.healthy).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Scheduled task heartbeats" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Scheduled task heartbeats"
                    description={`Every run of a Kiln-managed cron job reports a heartbeat. A task that does not report within ${defaultGraceSeconds}s of its expected time opens an issue.${unhealthy > 0 ? ` ${unhealthy} need attention.` : ''}`}
                />
                <Card className="py-0">
                    <HeartbeatTable monitors={monitors} canManage={can.manage} defaultGrace={defaultGraceSeconds} showSite />
                </Card>
            </div>
        </AppLayout>
    );
}
