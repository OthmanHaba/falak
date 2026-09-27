import { registerCommands, registerNavigation } from '@/lib/registry';
import { Bug, HeartPulse, Lightbulb } from 'lucide-react';

registerNavigation(
    { id: 'insights', title: 'Insights', url: '/insights', icon: Lightbulb, order: 300, permission: 'insights.view', activePrefix: '/insights' },
    { id: 'insights.issues', title: 'Issues', url: '/insights/issues', icon: Bug, order: 310, permission: 'insights.view' },
);

registerCommands({
    id: 'insights.navigation',
    commands: () => [
        {
            id: 'insights.index',
            title: 'Insights',
            group: 'Navigation',
            icon: Lightbulb,
            href: '/insights',
            permission: 'insights.view',
            keywords: ['apm', 'nightwatch', 'observability'],
            // Until the unified /observability page lands (§3), `g o` opens Insights.
            shortcut: 'G O',
        },
        {
            id: 'insights.issues',
            title: 'Open issues',
            group: 'Navigation',
            icon: Bug,
            href: '/insights/issues',
            permission: 'insights.view',
            keywords: ['exceptions', 'errors'],
        },
        {
            id: 'insights.mine',
            title: 'Issues assigned to me',
            group: 'Navigation',
            icon: Bug,
            href: '/insights/issues?assignee=me',
            permission: 'insights.view',
        },
        {
            id: 'insights.heartbeats',
            title: 'Scheduled task heartbeats',
            group: 'Navigation',
            icon: HeartPulse,
            href: '/insights/heartbeats',
            permission: 'insights.view',
            keywords: ['cron'],
        },
    ],
});
