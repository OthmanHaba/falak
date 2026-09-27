import { registerCommands, registerNavigation } from '@/lib/registry';
import { Network, Shield } from 'lucide-react';

registerNavigation({ id: 'network', title: 'Network', url: '/network', icon: Network, order: 400, permission: 'network.view' });

registerCommands({
    id: 'network.navigation',
    commands: () => [
        {
            id: 'network.index',
            title: 'Private networks',
            group: 'Navigation',
            icon: Network,
            href: '/network',
            permission: 'network.view',
            keywords: ['firewall', 'wireguard', 'private network', 'load balancer'],
        },
        {
            id: 'network.firewalls',
            title: 'Firewalls',
            group: 'Navigation',
            icon: Shield,
            href: '/network',
            permission: 'network.view',
            keywords: ['nftables', 'ports', 'firewall rules'],
        },
    ],
});
