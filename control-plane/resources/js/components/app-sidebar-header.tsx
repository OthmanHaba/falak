import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { headerItemsFor, shellContext } from '@/lib/registry';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { props } = usePage<SharedData>();
    const items = props.organization?.current ? headerItemsFor(shellContext(props)) : [];

    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {items.length > 0 && (
                <div className="ml-auto flex items-center gap-1">
                    {items.map((item) => (
                        <item.component key={item.id} />
                    ))}
                </div>
            )}
        </header>
    );
}
