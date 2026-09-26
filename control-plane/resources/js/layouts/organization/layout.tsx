import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

interface OrganizationNavItem {
    title: string;
    url: string;
    permission?: string;
}

const items: OrganizationNavItem[] = [
    { title: 'General', url: '/organization/settings' },
    { title: 'Members', url: '/organization/members', permission: 'members.view' },
    { title: 'Teams', url: '/organization/teams', permission: 'members.view' },
    { title: 'Audit log', url: '/organization/audit-log', permission: 'audit.view' },
];

export default function OrganizationLayout({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
    const { props, url } = usePage<SharedData>();
    const current = props.organization?.current ?? null;
    const permissions = current?.permissions ?? [];
    const path = url.split('?')[0];

    return (
        <div className="px-4 py-6">
            <Heading title={current?.name ?? 'Organization'} description="Manage your organization, its members and access" />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {items
                            .filter((item) => !item.permission || permissions.includes(item.permission))
                            .map((item) => (
                                <Button
                                    key={item.url}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', { 'bg-muted': path === item.url })}
                                >
                                    <Link href={item.url} prefetch>
                                        {item.title}
                                    </Link>
                                </Button>
                            ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className={cn('flex-1', wide ? 'min-w-0' : 'md:max-w-3xl')}>
                    <section className="space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
