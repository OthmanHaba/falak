import {
    registerCommands,
    registerNavigation,
    registerServiceSettingsSections,
    registerServiceTabs,
    registerSiteTabs,
    type PaletteCommand,
    type ServiceTabProps,
} from '@/lib/registry';
import { Globe, Plus } from 'lucide-react';
import { lazy, type ComponentType } from 'react';

// Canvas service panel (docs/UI_DESIGN.md §5.1): Variables 200, Settings 900. Panel code loads with the canvas.
const VariablesTab = lazy(() => import('./panel/variables-tab').then((module) => ({ default: module.VariablesTab })));

const SettingsTab = lazy(() => import('./panel/settings-tab').then((module) => ({ default: module.SettingsTab })));

registerServiceTabs(
    { id: 'variables', kinds: ['site'], title: 'Variables', order: 200, permission: 'sites.view', component: VariablesTab },
    { id: 'settings', kinds: ['site'], title: 'Settings', order: 900, permission: 'sites.view', component: SettingsTab },
);

type Blocks = typeof import('./panel/settings/general');
const general = (name: keyof Blocks): ComponentType<ServiceTabProps> =>
    lazy(() => import('./panel/settings/general').then((module) => ({ default: module[name] })));

// Settings tab sections owned by Sites (Deployments and Edge add theirs: deploy strategy, domains, routing …).
registerServiceSettingsSections(
    { id: 'sites.source', kinds: ['site'], section: 'source', sectionTitle: 'Source', order: 100, permission: 'sites.view', component: general('SourceSettings') },
    { id: 'sites.build', kinds: ['site'], section: 'build', sectionTitle: 'Build', order: 200, permission: 'sites.view', component: general('BuildSettings') },
    {
        id: 'sites.deploy-script',
        kinds: ['site'],
        section: 'deploy',
        sectionTitle: 'Deploy',
        order: 320,
        permission: 'sites.view',
        component: lazy(() => import('./panel/settings/deploy-script').then((module) => ({ default: module.DeployScriptSettings }))),
    },
    { id: 'sites.shared-paths', kinds: ['site'], section: 'deploy', sectionTitle: 'Deploy', order: 330, permission: 'sites.view', component: general('SharedPathsSettings') },
    {
        id: 'sites.test-domain',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 420,
        permission: 'sites.view',
        component: general('TestDomainSettings'),
    },
    { id: 'sites.servers', kinds: ['site'], section: 'servers', sectionTitle: 'Servers', order: 500, permission: 'sites.view', component: general('ServersSettings') },
    {
        id: 'sites.laravel',
        kinds: ['site'],
        section: 'laravel',
        sectionTitle: 'Laravel',
        order: 600,
        permission: 'sites.view',
        when: (ctx) => ctx.service.icon === 'laravel',
        component: general('LaravelSettings'),
    },
    {
        id: 'sites.commands',
        kinds: ['site'],
        section: 'commands',
        sectionTitle: 'Commands',
        order: 700,
        permission: 'sites.view',
        component: lazy(() => import('./panel/settings/commands').then((module) => ({ default: module.CommandsSettings }))),
    },
    { id: 'sites.danger', kinds: ['site'], section: 'danger', sectionTitle: 'Danger', order: 900, permission: 'sites.delete', component: general('DangerSettings') },
);

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
