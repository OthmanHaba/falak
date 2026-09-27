import { AppShell } from '@/components/kiln/app-shell';
import { EmptyState } from '@/components/kiln/empty-state';
import { PageHeader } from '@/components/kiln/section';
import { Head } from '@inertiajs/react';
import SiteLogs from '../../../../../Telemetry/resources/js/panels/SiteLogs';
import SiteMetrics from '../../../../../Telemetry/resources/js/panels/SiteMetrics';
import SiteObservability from '../../panels/SiteObservability';

/**
 * Local-only gallery (/dev/observability-panels?site=…) rendering the service-panel tab components exactly as the
 * canvas panel will host them: a `min(960px, 62vw)` column, each tab given only `{ siteId }`.
 */
export default function Panels({ siteId }: { siteId: string | null }) {
    return (
        <AppShell
            breadcrumbs={[
                { title: 'Dev', href: '/dev/components' },
                { title: 'Observability panels', href: '/dev/observability-panels' },
            ]}
        >
            <Head title="Observability panels" />
            <div className="grid gap-6">
                <PageHeader title="Service panel · observability tabs" description={siteId ? `Site ${siteId}` : undefined} />
                {siteId ? (
                    <div className="grid gap-6">
                        {[
                            { id: 'metrics', title: 'Metrics', node: <SiteMetrics siteId={siteId} /> },
                            { id: 'logs', title: 'Logs', node: <SiteLogs siteId={siteId} /> },
                            { id: 'observability', title: 'Observability', node: <SiteObservability siteId={siteId} /> },
                        ].map((tab) => (
                            <section
                                key={tab.id}
                                aria-label={tab.title}
                                className="border-border bg-surface-1 shadow-panel w-full max-w-[min(960px,100%)] rounded-xl border lg:max-w-[min(960px,62vw)]"
                            >
                                <h2 className="border-border text-fg border-b px-4 py-2.5 text-sm font-medium">{tab.title}</h2>
                                <div className="p-4">{tab.node}</div>
                            </section>
                        ))}
                    </div>
                ) : (
                    <EmptyState title="No site with insights data" description="Seed demo data (UiDemoSeeder) or pass ?site=<id>." />
                )}
            </div>
        </AppShell>
    );
}
