import { AppShell } from '@/components/kiln/app-shell';
import { PageHeader } from '@/components/kiln/section';
import { settingsNavFor, shellContext, type SettingsNavItem } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface SettingsLayoutProps {
    /** Section title (also the browser title). */
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** Let wide content (tables) use the full column. */
    wide?: boolean;
    children: ReactNode;
}

function NavGroup({ label, items, path }: { label: string; items: SettingsNavItem[]; path: string }) {
    if (items.length === 0) return null;

    return (
        <div className="grid gap-0.5 md:mb-4">
            <p className="text-2xs text-fg-faint hidden truncate px-2 pb-1 font-medium tracking-wide uppercase md:block">{label}</p>
            <ul className="flex gap-0.5 md:grid">
                {items.map((item) => {
                    const active = path === item.url || path.startsWith(`${item.url}/`);

                    return (
                        <li key={item.id}>
                            <Link
                                href={item.url}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex h-8 items-center gap-2 rounded-md px-2 text-sm whitespace-nowrap transition-colors duration-150',
                                    active ? 'bg-surface-2 text-fg font-medium' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
                                )}
                            >
                                {item.icon && <item.icon className="text-fg-faint hidden size-4 shrink-0 md:block" aria-hidden />}
                                {item.title}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * /settings/{section} shell (§3): left mini-nav (Account · Organization, extended by modules via registerSettingsNav)
 * and the section content.
 */
export default function SettingsLayout({ title, description, actions, wide = false, children }: SettingsLayoutProps) {
    const { props, url } = usePage<SharedData>();
    const items = settingsNavFor(shellContext(props));
    const path = url.split('?')[0];
    const orgName = props.organization?.current?.name ?? 'Organization';

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Settings', href: '/settings/profile' },
                { title, href: path },
            ]}
        >
            <Head title={`${title} · Settings`} />
            <div className="grid gap-6 md:grid-cols-[200px_minmax(0,1fr)] md:gap-10">
                <nav aria-label="Settings" className="-mx-4 overflow-x-auto px-4 md:mx-0 md:overflow-visible md:px-0">
                    <div className="border-border flex gap-4 border-b pb-2 md:sticky md:top-20 md:block md:border-0 md:pb-0">
                        <NavGroup label="Account" items={items.filter((item) => item.group === 'account')} path={path} />
                        <NavGroup label={orgName} items={items.filter((item) => item.group === 'organization')} path={path} />
                    </div>
                </nav>
                <div className={cn('grid min-w-0 content-start gap-8', !wide && 'max-w-3xl')}>
                    <PageHeader title={title} description={description} actions={actions} />
                    {children}
                </div>
            </div>
        </AppShell>
    );
}
