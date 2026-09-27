import { registerCommands, registerNavigation, registerSettingsNav } from '@/lib/registry';
import { Activity, Radar, ScrollText } from 'lucide-react';

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
    ],
});

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'observability',
    title: 'Observability',
    url: '/settings/observability',
    group: 'integrations',
    order: 240,
    icon: Radar,
    permission: 'telemetry.view',
    requiresOrganization: true,
    keywords: ['telemetry', 'otlp', 'grafana', 'loki', 'tempo', 'metrics', 'prometheus'],
});
