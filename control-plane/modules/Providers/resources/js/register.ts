import { registerCommands, registerNavigation } from '@/lib/registry';
import { Cloud, Plus } from 'lucide-react';

registerNavigation({
    id: 'providers',
    title: 'Providers',
    url: '/providers',
    icon: Cloud,
    order: 150,
    permission: 'providers.view',
});

registerCommands({
    id: 'providers.navigation',
    commands: () => [
        {
            id: 'providers.index',
            title: 'Providers',
            group: 'Navigation',
            icon: Cloud,
            href: '/providers',
            permission: 'providers.view',
            keywords: ['cloud', 'hetzner', 'digitalocean', 'vultr', 'linode', 'aws'],
        },
        {
            id: 'providers.add',
            title: 'Add provider credential',
            group: 'Actions',
            icon: Plus,
            href: '/providers?add=1',
            permission: 'providers.manage',
            keywords: ['cloud', 'token', 'api key'],
        },
    ],
});
