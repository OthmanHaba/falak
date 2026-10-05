import { Button } from '@/components/falak/button';
import { CopyButton } from '@/components/falak/copy-button';
import ObservabilityLayout from '@/layouts/observability-layout';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, ScrollText } from 'lucide-react';
import { TraceView } from '../components/trace-waterfall';

interface Props {
    traceId: string;
    links: { logs: string; grafana: string | null };
}

export default function Trace({ traceId, links }: Props) {
    return (
        <ObservabilityLayout
            tab="traces"
            title={`Trace ${traceId.slice(0, 12)} · Observability`}
            breadcrumbs={[{ title: traceId.slice(0, 12), href: `/observability/traces/${traceId}` }]}
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid min-w-0 gap-1">
                        <Link href="/observability/traces" className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1 text-xs">
                            <ArrowLeft className="size-3.5" /> Traces
                        </Link>
                        <h1 className="text-fg text-lg font-semibold">Trace</h1>
                        <p className="text-fg-muted flex min-w-0 items-center gap-1 font-mono text-xs">
                            <span className="truncate">{traceId}</span>
                            <CopyButton value={traceId} size="xs" label="Copy trace id" />
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="secondary">
                            <Link href={links.logs}>
                                <ScrollText /> Logs for this trace
                            </Link>
                        </Button>
                        {links.grafana && (
                            <Button asChild variant="ghost">
                                <a href={links.grafana} target="_blank" rel="noreferrer">
                                    <ExternalLink /> Grafana
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
            }
        >
            <TraceView traceId={traceId} />
        </ObservabilityLayout>
    );
}
