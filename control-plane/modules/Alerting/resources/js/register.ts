import { registerCommands, registerHeaderItems, registerNavigation, registerSettingsNav } from '@/lib/registry';
import { Bell, BellRing, History, Send } from 'lucide-react';
import { NotificationBell } from './components/notification-bell';

registerNavigation({
    id: 'alerting',
    title: 'Alerts',
    url: '/alerting/history',
    icon: BellRing,
    order: 500,
    permission: 'alerting.view',
    activePrefix: '/alerting',
});

registerHeaderItems({ id: 'alerting.notifications', order: 100, component: NotificationBell });

registerCommands({
    id: 'alerting.navigation',
    commands: () => [
        {
            id: 'alerting.history',
            title: 'Alert history',
            group: 'Navigation',
            icon: History,
            href: '/alerting/history',
            permission: 'alerting.view',
        },
        {
            id: 'alerting.notifications',
            title: 'Notifications',
            group: 'Navigation',
            icon: Bell,
            href: '/notifications',
            keywords: ['inbox', 'unread'],
        },
    ],
});

// Organization settings (docs/UI_DESIGN.md §3): rules are org policy, channels are integrations.
registerSettingsNav(
    {
        id: 'alert-rules',
        title: 'Alert rules',
        url: '/settings/alert-rules',
        group: 'organization',
        order: 160,
        icon: BellRing,
        permission: 'alerting.view',
        requiresOrganization: true,
        keywords: ['alerts', 'routing', 'quiet hours', 'rate limit'],
    },
    {
        id: 'alert-channels',
        title: 'Alert channels',
        url: '/settings/alert-channels',
        group: 'integrations',
        order: 250,
        icon: Send,
        permission: 'alerting.view',
        requiresOrganization: true,
        keywords: ['slack', 'discord', 'telegram', 'webhook', 'email', 'alerts'],
    },
);
