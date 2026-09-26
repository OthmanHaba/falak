import { registerCommands, registerHeaderItems, registerNavigation } from '@/lib/registry';
import { Bell, BellRing, History, Send } from 'lucide-react';
import { NotificationBell } from './components/notification-bell';

registerNavigation({
    id: 'alerting',
    title: 'Alerts',
    url: '/alerting/rules',
    icon: BellRing,
    order: 500,
    permission: 'alerting.view',
    activePrefix: '/alerting',
});

registerHeaderItems({ id: 'alerting.notifications', order: 100, component: NotificationBell });

registerCommands({
    id: 'alerting.navigation',
    commands: () => [
        { id: 'alerting.rules', title: 'Alert rules', group: 'Navigation', icon: BellRing, href: '/alerting/rules', permission: 'alerting.view' },
        {
            id: 'alerting.channels',
            title: 'Alert channels',
            group: 'Navigation',
            icon: Send,
            href: '/alerting/channels',
            permission: 'alerting.view',
            keywords: ['slack', 'discord', 'telegram', 'webhook', 'email'],
        },
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
