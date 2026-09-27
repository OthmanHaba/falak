import { navigationFor, shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Check, Plus, Settings, Users } from 'lucide-react';
import { useMemo } from 'react';
import { Avatar } from './avatar';
import { MenuContent, MenuItem, MenuLabel, MenuLink, MenuRoot, MenuSeparator, MenuTrigger } from './menu';
import { SwitcherTrigger } from './switcher-trigger';

/**
 * Organization switcher; also the "org menu" that lists the top-level destinations registered by modules
 * (registerNavigation) now that there is no permanent sidebar.
 */
export function OrgSwitcher() {
    const { props } = usePage<SharedData>();
    const organization = props.organization;
    const current = organization?.current ?? null;
    const destinations = useMemo(() => navigationFor(shellContext(props)), [props]);

    const switchTo = (id: string) => {
        if (id !== current?.id) router.put(route('organizations.switch'), { organization_id: id });
    };

    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <SwitcherTrigger
                    data-testid="organization-switcher"
                    aria-label={`Organization: ${current?.name ?? 'none'}`}
                    icon={<Avatar name={current?.name ?? '?'} size="xs" square />}
                    label={<span className="hidden sm:inline">{current?.name ?? 'No organization'}</span>}
                />
            </MenuTrigger>
            <MenuContent align="start" className="w-64">
                <MenuLabel>Organizations</MenuLabel>
                {(organization?.all ?? []).map((org) => (
                    <MenuItem key={org.id} onSelect={() => switchTo(org.id)} icon={<Avatar name={org.name} size="xs" square />}>
                        <span className="flex items-center gap-2">
                            {org.name}
                            {org.id === current?.id && <Check className="text-primary ml-auto size-4" aria-label="Current" />}
                        </span>
                    </MenuItem>
                ))}
                <MenuLink href={route('organizations.create')} icon={<Plus />}>
                    New organization
                </MenuLink>
                {current && (
                    <>
                        <MenuSeparator />
                        <MenuLink href="/settings/organization" icon={<Settings />}>
                            Organization settings
                        </MenuLink>
                        <MenuLink href="/settings/members" icon={<Users />}>
                            Members
                        </MenuLink>
                    </>
                )}
                {destinations.length > 0 && (
                    <>
                        <MenuSeparator />
                        <MenuLabel>Go to</MenuLabel>
                        {destinations.map((item) => (
                            <MenuLink key={item.id} href={item.url} icon={<item.icon />}>
                                {item.title}
                            </MenuLink>
                        ))}
                    </>
                )}
            </MenuContent>
        </MenuRoot>
    );
}
