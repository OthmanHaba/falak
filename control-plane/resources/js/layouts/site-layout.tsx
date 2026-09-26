import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { shellContext, siteTabsFor } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Header data every site page receives as the `site` prop (see Sites\Http\Controllers\PresentsSites::header()).
 */
export interface SiteHeader {
    id: string;
    name: string;
    slug: string;
    runtime: string;
    runtime_label: string;
    framework_label: string;
    repository: string | null;
    branch: string | null;
    primary_domain: string | null;
    test_domain: string | null;
    servers: { id: string; name: string; role: string }[];
}

interface SiteLayoutProps {
    site: SiteHeader;
    /** Page title (browser tab + breadcrumb). */
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}

/**
 * Shell for all /sites/{id}/* pages: breadcrumbs, site header and the module-registered tab bar.
 */
export default function SiteLayout({ site, title, actions, children }: SiteLayoutProps) {
    const { props, url } = usePage<SharedData>();
    const base = `/sites/${site.id}`;
    const tabs = siteTabsFor(shellContext(props));
    const path = url.split('?')[0];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sites', href: '/sites' },
        { title: site.name, href: base },
        ...(title === 'Overview' ? [] : [{ title, href: path }]),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${title} · ${site.name}`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">{site.name}</h1>
                            <Badge variant="outline">{site.runtime_label}</Badge>
                            <Badge variant="secondary">{site.framework_label}</Badge>
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {site.primary_domain ?? site.test_domain ?? site.slug}
                            {site.repository && (
                                <>
                                    {' · '}
                                    <span className="font-mono">
                                        {site.repository}
                                        {site.branch ? `@${site.branch}` : ''}
                                    </span>
                                </>
                            )}
                            {site.servers.length > 0 && ` · ${site.servers.map((server) => server.name).join(', ')}`}
                        </p>
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </div>

                <nav className="-mb-px flex gap-1 overflow-x-auto border-b" aria-label="Site sections">
                    {tabs.map((tab) => {
                        const href = tab.path ? `${base}/${tab.path}` : base;
                        const active = tab.path ? path === href || path.startsWith(`${href}/`) : path === base;

                        return (
                            <Link
                                key={tab.id}
                                href={href}
                                prefetch
                                className={cn(
                                    'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                    active ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                                )}
                            >
                                {tab.title}
                            </Link>
                        );
                    })}
                </nav>

                {children}
            </div>
        </AppLayout>
    );
}
