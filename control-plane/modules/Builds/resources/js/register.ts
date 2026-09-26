import { registerCommands, registerNavigation } from '@/lib/registry';
import { Hammer } from 'lucide-react';

registerNavigation({ id: 'builds', title: 'Builds', url: '/builds', icon: Hammer, order: 250, permission: 'builds.view' });

registerCommands({
    id: 'builds.navigation',
    commands: () => [
        { id: 'builds.index', title: 'Builds', group: 'Navigation', icon: Hammer, href: '/builds', permission: 'builds.view' },
        {
            id: 'builds.builders',
            title: 'Builders',
            group: 'Navigation',
            icon: Hammer,
            href: '/builds/builders',
            permission: 'builds.view',
            keywords: ['builder', 'buildkit', 'railpack'],
        },
    ],
});
