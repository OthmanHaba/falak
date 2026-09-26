import { openCommandPalette } from '@/components/command-palette';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { OrganizationSwitcher } from '@/components/organization-switcher';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { navigationFor, shellContext } from '@/lib/registry';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, Search } from 'lucide-react';
import { useMemo } from 'react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { props } = usePage<SharedData>();

    const mainNavItems: NavItem[] = useMemo(
        () => [
            { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
            ...navigationFor(shellContext(props)).map((item) => ({ title: item.title, url: item.url, icon: item.icon })),
        ],
        [props],
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <OrganizationSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <SidebarMenu className="px-2">
                    <SidebarMenuItem>
                        <SidebarMenuButton onClick={openCommandPalette} tooltip="Search (⌘K)">
                            <Search />
                            <span>Search</span>
                            <kbd className="bg-muted text-muted-foreground ml-auto rounded border px-1.5 font-mono text-[10px]">⌘K</kbd>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
