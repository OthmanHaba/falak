import { AppShell } from '@/components/kiln/app-shell';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

/**
 * Layout for pages not yet rebuilt on the new components: renders them inside the Kiln AppShell.
 * (Their own `p-4` wrappers supply most of the page gutter, so the shell padding is reduced.)
 */
export default function AppLayout({ children, breadcrumbs }: AppLayoutProps) {
    return (
        <AppShell breadcrumbs={breadcrumbs} className="px-1 py-2 md:px-2">
            {children}
        </AppShell>
    );
}
