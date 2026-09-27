import { registerCommands, registerNavigation } from '@/lib/registry';
import { Bug, Gauge, HeartPulse } from 'lucide-react';

registerNavigation(
    {
        id: 'insights',
        title: 'Observability',
        url: '/observability',
        icon: Gauge,
        order: 300,
        permission: 'insights.view',
        activePrefix: '/observability',
    },
    { id: 'insights.issues', title: 'Issues', url: '/observability/issues', icon: Bug, order: 310, permission: 'insights.view' },
);

registerCommands({
    id: 'insights.navigation',
    commands: () => [
        {
            id: 'insights.index',
            title: 'Observability',
            group: 'Navigation',
            icon: Gauge,
            href: '/observability',
            permission: 'insights.view',
            keywords: ['apm', 'nightwatch', 'insights', 'overview', 'health'],
            shortcut: 'G O',
        },
        {
            id: 'insights.issues',
            title: 'Open issues',
            group: 'Navigation',
            icon: Bug,
            href: '/observability/issues',
            permission: 'insights.view',
            keywords: ['exceptions', 'errors'],
        },
        {
            id: 'insights.mine',
            title: 'Issues assigned to me',
            group: 'Navigation',
            icon: Bug,
            href: '/observability/issues?assignee=me',
            permission: 'insights.view',
        },
        {
            id: 'insights.heartbeats',
            title: 'Scheduled task heartbeats',
            group: 'Navigation',
            icon: HeartPulse,
            href: '/observability/heartbeats',
            permission: 'insights.view',
            keywords: ['cron', 'schedule', 'missed'],
        },
    ],
});
