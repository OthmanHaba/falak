import { registerCommands } from '@/lib/registry';
import { router } from '@inertiajs/react';
import { Building2, KeyRound, LogOut, Plus, ScrollText, Settings, ShieldCheck, User, Users, UsersRound } from 'lucide-react';

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
        id: 'identity.navigation',
        commands: () => [
            { id: 'identity.org.settings', title: 'Organization settings', group: 'Organization', icon: Settings, href: '/organization/settings' },
            {
                id: 'identity.org.members',
                title: 'Members',
                group: 'Organization',
                icon: Users,
                href: '/organization/members',
                permission: 'members.view',
            },
            {
                id: 'identity.org.teams',
                title: 'Teams',
                group: 'Organization',
                icon: UsersRound,
                href: '/organization/teams',
                permission: 'members.view',
            },
            {
                id: 'identity.org.audit',
                title: 'Audit log',
                group: 'Organization',
                icon: ScrollText,
                href: '/organization/audit-log',
                permission: 'audit.view',
            },
            { id: 'identity.org.create', title: 'New organization', group: 'Organization', icon: Plus, href: '/organizations/create' },
            { id: 'identity.settings.profile', title: 'Profile', group: 'Settings', icon: User, href: '/settings/profile' },
            {
                id: 'identity.settings.tokens',
                title: 'API tokens',
                group: 'Settings',
                icon: KeyRound,
                href: '/settings/api-tokens',
                keywords: ['token', 'cli'],
            },
            {
                id: 'identity.settings.2fa',
                title: 'Two-factor authentication',
                group: 'Settings',
                icon: ShieldCheck,
                href: '/settings/two-factor',
                keywords: ['2fa', 'mfa', 'totp'],
            },
            { id: 'identity.logout', title: 'Log out', group: 'Settings', icon: LogOut, perform: () => router.post(route('logout')) },
        ],
    },
);
