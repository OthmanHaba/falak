import { registerCommands, registerNavigation, type PaletteCommand } from '@/lib/registry';
import { KeyRound, Plus, Server } from 'lucide-react';

registerNavigation(
    { id: 'servers', title: 'Servers', url: '/servers', icon: Server, order: 100, permission: 'servers.view' },
    { id: 'ssh-keys', title: 'SSH keys', url: '/ssh-keys', icon: KeyRound, order: 110, permission: 'servers.view' },
);

interface ServerSearchResult {
    id: string;
    name: string;
    ipv4: string | null;
    type: string;
    status: string;
}

registerCommands(
    {
        id: 'servers.navigation',
        commands: () => [
            { id: 'servers.index', title: 'Servers', group: 'Navigation', icon: Server, href: '/servers', permission: 'servers.view' },
            { id: 'servers.create', title: 'Create server', group: 'Actions', icon: Plus, href: '/servers/create', permission: 'servers.create' },
            { id: 'servers.ssh-keys', title: 'SSH keys', group: 'Navigation', icon: KeyRound, href: '/ssh-keys', permission: 'servers.view' },
        ],
    },
    {
        id: 'servers.search',
        minQueryLength: 1,
        commands: async ({ query, can }): Promise<PaletteCommand[]> => {
            if (!can('servers.view')) {
                return [];
            }

            const response = await fetch(`/servers/search?q=${encodeURIComponent(query)}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return [];
            }

            const body = (await response.json()) as { data: ServerSearchResult[] };

            return body.data.map((server) => ({
                id: `server.${server.id}`,
                title: server.name,
                group: 'Servers',
                icon: Server,
                href: `/servers/${server.id}`,
                keywords: [server.ipv4 ?? '', server.type, server.status, query].filter(Boolean),
            }));
        },
    },
);
