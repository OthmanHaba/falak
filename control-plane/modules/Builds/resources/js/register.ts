import { registerCommands, registerNavigation, registerSettingsNav } from '@/lib/registry';
import { Hammer } from 'lucide-react';

registerNavigation({ id: 'builds', title: 'Builds', url: '/builds', icon: Hammer, order: 250, permission: 'builds.view' });

registerCommands({
    id: 'builds.navigation',
    commands: () => [
        { id: 'builds.index', title: 'Builds', group: 'Navigation', icon: Hammer, href: '/builds', permission: 'builds.view' },
    ],
});

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'builders',
    title: 'Builders',
    url: '/settings/builders',
    group: 'integrations',
    order: 230,
    icon: Hammer,
    permission: 'builds.view',
    requiresOrganization: true,
    keywords: ['builder', 'buildkit', 'railpack', 'ci'],
});
