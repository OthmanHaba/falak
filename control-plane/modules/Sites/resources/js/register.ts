import { registerCommands, registerNavigation, registerSiteTabs, type PaletteCommand } from '@/lib/registry';
import { Globe, Plus } from 'lucide-react';

registerNavigation({ id: 'sites', title: 'Sites', url: '/sites', icon: Globe, order: 200, permission: 'sites.view' });

registerSiteTabs(
    { id: 'sites.overview', title: 'Overview', path: '', order: 0, permission: 'sites.view' },
    { id: 'sites.environment', title: 'Environment', path: 'environment', order: 300, permission: 'sites.view' },
    { id: 'sites.deploy-script', title: 'Deploy script', path: 'deploy-script', order: 400, permission: 'sites.view' },
    { id: 'sites.commands', title: 'Commands', path: 'commands', order: 600, permission: 'sites.view' },
    { id: 'sites.settings', title: 'Settings', path: 'settings', order: 900, permission: 'sites.view' },
);

interface SiteSearchResult {
    id: string;
    name: string;
    slug: string;
    runtime: string;
    repository: string | null;
}

registerCommands(
    {
        id: 'sites.navigation',
        commands: () => [
            { id: 'sites.index', title: 'Sites', group: 'Navigation', icon: Globe, href: '/sites', permission: 'sites.view' },
            { id: 'sites.create', title: 'Create site', group: 'Actions', icon: Plus, href: '/sites/create', permission: 'sites.create' },
        ],
    },
    {
        id: 'sites.search',
        minQueryLength: 1,
        commands: async ({ query, can }): Promise<PaletteCommand[]> => {
            if (!can('sites.view')) {
                return [];
            }

            const response = await fetch(`/sites/search?q=${encodeURIComponent(query)}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return [];
            }

            const body = (await response.json()) as { data: SiteSearchResult[] };

            return body.data.flatMap((site) => [
                {
                    id: `site.${site.id}`,
                    title: site.name,
                    group: 'Sites',
                    icon: Globe,
                    href: `/sites/${site.id}`,
                    keywords: [site.slug, site.runtime, site.repository ?? '', query].filter(Boolean),
                },
                {
                    id: `site.${site.id}.environment`,
                    title: `${site.name}: environment`,
                    group: 'Sites',
                    href: `/sites/${site.id}/environment`,
                    keywords: [site.slug, 'env', 'variables', query],
                },
            ]);
        },
    },
);
