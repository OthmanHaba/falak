import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ExternalLink, ScrollText } from 'lucide-react';
import { TraceTimelineCard } from '../components/trace-waterfall';

interface Props {
    traceId: string;
    links: { logs: string; grafana: string | null };
}

export default function Trace({ traceId, links }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Traces', href: '/telemetry/traces' },
        { title: traceId.slice(0, 12), href: `/telemetry/traces/${traceId}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Trace ${traceId.slice(0, 12)}`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Trace" description={traceId} />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={links.logs}>
                                <ScrollText /> Logs for this trace
                            </Link>
                        </Button>
                        {links.grafana && (
                            <Button variant="outline" asChild>
                                <a href={links.grafana} target="_blank" rel="noreferrer">
                                    <ExternalLink /> Grafana
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
                <TraceTimelineCard traceId={traceId} title="Timeline" />
            </div>
        </AppLayout>
    );
}
