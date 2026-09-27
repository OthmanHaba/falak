import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Check, KeyRound, LogOut, Monitor, Moon, Settings, Sun } from 'lucide-react';
import { Avatar } from './avatar';
import { MenuContent, MenuLabel, MenuLink, MenuRoot, MenuSeparator, MenuTrigger } from './menu';

const THEMES: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'system', label: 'System', icon: Monitor },
];

export function UserMenu() {
    const { auth } = usePage<SharedData>().props;
    const { appearance, updateAppearance } = useAppearance();
    const user = auth.user;

    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <button type="button" className="rounded-full" aria-label="Account menu" data-testid="user-menu">
                    <Avatar name={user.name} src={user.avatar} size="md" />
                </button>
            </MenuTrigger>
            <MenuContent className="w-60">
                <div className="flex items-center gap-2.5 px-2 py-2">
                    <Avatar name={user.name} src={user.avatar} size="md" />
                    <div className="grid min-w-0">
                        <span className="text-fg truncate text-sm font-medium">{user.name}</span>
                        <span className="text-fg-faint truncate text-xs">{user.email}</span>
                    </div>
                </div>
                <MenuSeparator />
                <MenuLink href="/settings/profile" icon={<Settings />}>
                    Account settings
                </MenuLink>
                <MenuLink href="/settings/api-tokens" icon={<KeyRound />}>
                    API tokens
                </MenuLink>
                <MenuSeparator />
                <MenuLabel>Theme</MenuLabel>
                <DropdownMenu.RadioGroup value={appearance} onValueChange={(value) => updateAppearance(value as Appearance)}>
                    {THEMES.map((theme) => (
                        <DropdownMenu.RadioItem
                            key={theme.value}
                            value={theme.value}
                            onSelect={(event) => event.preventDefault()}
                            className="text-fg data-[highlighted]:bg-surface-2 relative flex cursor-default items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none select-none"
                        >
                            <theme.icon className="text-fg-muted size-4" aria-hidden />
                            {theme.label}
                            <DropdownMenu.ItemIndicator className="ml-auto">
                                <Check className="text-primary size-4" aria-hidden />
                            </DropdownMenu.ItemIndicator>
                        </DropdownMenu.RadioItem>
                    ))}
                </DropdownMenu.RadioGroup>
                <MenuSeparator />
                <MenuLink href={route('logout')} method="post" icon={<LogOut />}>
                    Log out
                </MenuLink>
            </MenuContent>
        </MenuRoot>
    );
}
