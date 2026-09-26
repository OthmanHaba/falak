import { registerCommands, registerNavigation } from '@/lib/registry';
import { Activity, ScrollText, Settings2 } from 'lucide-react';

registerNavigation(
    { id: 'telemetry.logs', title: 'Logs', url: '/telemetry/logs', icon: ScrollText, order: 400, permission: 'telemetry.view' },
    { id: 'telemetry.traces', title: 'Traces', url: '/telemetry/traces', icon: Activity, order: 410, permission: 'telemetry.view' },
);

registerCommands({
    id: 'telemetry.navigation',
    commands: () => [
        {
            id: 'telemetry.logs',
            title: 'Logs',
            group: 'Navigation',
            icon: ScrollText,
            href: '/telemetry/logs',
            permission: 'telemetry.view',
            keywords: ['loki'],
        },
        {
            id: 'telemetry.traces',
            title: 'Traces',
            group: 'Navigation',
            icon: Activity,
            href: '/telemetry/traces',
            permission: 'telemetry.view',
            keywords: ['tempo', 'apm'],
        },
        {
            id: 'telemetry.settings',
            title: 'Telemetry settings',
            group: 'Settings',
            icon: Settings2,
            href: '/telemetry/settings',
            permission: 'telemetry.view',
            keywords: ['otlp', 'grafana', 'observability'],
        },
    ],
});
