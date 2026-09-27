import { registerCommands, registerSettingsNav } from '@/lib/registry';
import { router } from '@inertiajs/react';
import { Building2, KeyRound, Lock, LogOut, Palette, Plus, ScrollText, Settings, ShieldCheck, User, Users, UsersRound } from 'lucide-react';

// /settings/{section} mini-nav. The palette lists these under "Settings" automatically.
registerSettingsNav(
    { id: 'profile', title: 'Profile', url: '/settings/profile', group: 'account', order: 0, icon: User, keywords: ['name', 'email', 'account'] },
    { id: 'password', title: 'Password', url: '/settings/password', group: 'account', order: 10, icon: Lock },
    {
        id: 'two-factor',
        title: 'Two-factor auth',
        url: '/settings/two-factor',
        group: 'account',
        order: 20,
        icon: ShieldCheck,
        keywords: ['2fa', 'mfa', 'totp'],
    },
    {
        id: 'appearance',
        title: 'Appearance',
        url: '/settings/appearance',
        group: 'account',
        order: 30,
        icon: Palette,
        keywords: ['theme', 'dark', 'light'],
    },
    {
        id: 'api-tokens',
        title: 'API tokens',
        url: '/settings/api-tokens',
        group: 'account',
        order: 40,
        icon: KeyRound,
        requiresOrganization: true,
        keywords: ['token', 'cli'],
    },
    {
        id: 'organization',
        title: 'General',
        url: '/settings/organization',
        group: 'organization',
        order: 100,
        icon: Settings,
        requiresOrganization: true,
        keywords: ['organization', 'rename', 'transfer'],
    },
    {
        id: 'members',
        title: 'Members',
        url: '/settings/members',
        group: 'organization',
        order: 110,
        icon: Users,
        permission: 'members.view',
        keywords: ['invite', 'invitations', 'roles'],
    },
    { id: 'teams', title: 'Teams', url: '/settings/teams', group: 'organization', order: 120, icon: UsersRound, permission: 'members.view' },
    {
        id: 'audit-log',
        title: 'Audit log',
        url: '/settings/audit-log',
        group: 'organization',
        order: 130,
        icon: ScrollText,
        permission: 'audit.view',
        keywords: ['history', 'activity'],
    },
);

registerCommands(
    {
        id: 'identity.organizations',
        commands: ({ props }) => {
            const current = props.organization?.current?.id;

            return (props.organization?.all ?? [])
                .filter((org) => org.id !== current)
                .map((org) => ({
                    id: `identity.switch.${org.id}`,
                    title: `Switch to ${org.name}`,
                    group: 'Organization',
                    icon: Building2,
                    keywords: ['switch', 'organization', org.slug],
                    perform: () => router.put(route('organizations.switch'), { organization_id: org.id }),
                }));
        },
    },
    {
        id: 'identity.actions',
        commands: () => [
            { id: 'identity.org.create', title: 'New organization', group: 'Organization', icon: Plus, href: '/organizations/create' },
            {
                id: 'identity.org.invite',
                title: 'Invite a member',
                group: 'Organization',
                icon: Users,
                href: '/settings/members',
                permission: 'members.manage',
                keywords: ['invitation', 'add user'],
            },
            { id: 'identity.logout', title: 'Log out', group: 'Settings', icon: LogOut, perform: () => router.post(route('logout')) },
        ],
    },
);
