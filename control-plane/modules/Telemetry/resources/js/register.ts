import { registerCommands, registerNavigation } from '@/lib/registry';
import { Activity, ScrollText, Settings2 } from 'lucide-react';

registerNavigation(
    { id: 'telemetry.logs', title: 'Logs', url: '/observability/logs', icon: ScrollText, order: 400, permission: 'telemetry.view' },
    { id: 'telemetry.traces', title: 'Traces', url: '/observability/traces', icon: Activity, order: 410, permission: 'telemetry.view' },
);

registerCommands({
    id: 'telemetry.navigation',
    commands: () => [
        {
            id: 'telemetry.logs',
            title: 'Logs',
            group: 'Navigation',
            icon: ScrollText,
            href: '/observability/logs',
            permission: 'telemetry.view',
            keywords: ['loki', 'observability', 'tail'],
        },
        {
            id: 'telemetry.traces',
            title: 'Traces',
            group: 'Navigation',
            icon: Activity,
            href: '/observability/traces',
            permission: 'telemetry.view',
            keywords: ['tempo', 'apm', 'observability', 'spans'],
        },
        {
            id: 'telemetry.traces.errors',
            title: 'Traces with errors',
            group: 'Navigation',
            icon: Activity,
            href: '/observability/traces?status=error',
            permission: 'telemetry.view',
            keywords: ['tempo', 'failed'],
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
