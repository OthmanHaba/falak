import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Plus, Settings } from 'lucide-react';

export function OrganizationSwitcher() {
    const { organization } = usePage<SharedData>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const current = organization?.current ?? null;

    const switchTo = (id: string) => {
        if (id !== current?.id) {
            router.put(route('organizations.switch'), { organization_id: id });
        }
    };

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton size="lg" className="data-[state=open]:bg-sidebar-accent" data-testid="organization-switcher">
                            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg">
                                <Building2 className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">{current?.name ?? 'No organization'}</span>
                                <span className="text-muted-foreground truncate text-xs capitalize">{current?.role ?? '—'}</span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={isMobile ? 'bottom' : state === 'collapsed' ? 'right' : 'bottom'}
                    >
                        <DropdownMenuLabel className="text-muted-foreground text-xs">Organizations</DropdownMenuLabel>
                        {(organization?.all ?? []).map((org) => (
                            <DropdownMenuItem key={org.id} onSelect={() => switchTo(org.id)} className="gap-2">
                                <Building2 className="size-4" />
                                <span className="truncate">{org.name}</span>
                                {org.id === current?.id && <Check className="ml-auto size-4" />}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        {current && (
                            <DropdownMenuItem asChild>
                                <Link href={route('organization.settings')} className="gap-2">
                                    <Settings className="size-4" />
                                    Organization settings
                                </Link>
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem asChild>
                            <Link href={route('organizations.create')} className="gap-2">
                                <Plus className="size-4" />
                                New organization
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
