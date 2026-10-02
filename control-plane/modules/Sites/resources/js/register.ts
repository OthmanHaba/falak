import {
    registerCommands,
    registerComposeProject,
    registerServiceSettingsSections,
    registerServiceTabs,
    registerSettingsNav,
    type PaletteCommand,
    type ServiceTabProps,
} from '@/lib/registry';
import { Boxes, Globe } from 'lucide-react';
import { lazy, type ComponentType } from 'react';
import { isCompose } from './panel/compose/api';

// Canvas service panel (docs/UI_DESIGN.md §5.1): Variables 200, Settings 900. Panel code loads with the canvas.
const VariablesTab = lazy(() => import('./panel/variables-tab').then((module) => ({ default: module.VariablesTab })));

const ServicesTab = lazy(() => import('./panel/compose/services-tab').then((module) => ({ default: module.ServicesTab })));

const SettingsTab = lazy(() => import('./panel/settings-tab').then((module) => ({ default: module.SettingsTab })));

// The Git create flow's "Docker Compose app" (docs/plans/COMPOSE_APPS.md).
registerComposeProject(lazy(() => import('./panel/compose/compose-project-form').then((module) => ({ default: module.ComposeProjectForm }))));

registerServiceTabs(
    // Docker Compose sites (docs/COMPOSE_TEMPLATES.md §1.6): after Deployments.
    { id: 'services', kinds: ['site'], title: 'Services', order: 150, permission: 'sites.view', when: isCompose, component: ServicesTab },
    { id: 'variables', kinds: ['site'], title: 'Variables', order: 200, permission: 'sites.view', component: VariablesTab },
    { id: 'settings', kinds: ['site'], title: 'Settings', order: 900, permission: 'sites.view', component: SettingsTab },
);

type Blocks = typeof import('./panel/settings/general');
const general = (name: keyof Blocks): ComponentType<ServiceTabProps> =>
    lazy(() => import('./panel/settings/general').then((module) => ({ default: module[name] })));

// Settings tab sections owned by Sites (Deployments and Edge add theirs: deploy strategy, domains, routing …).
registerServiceSettingsSections(
    {
        id: 'sites.source',
        kinds: ['site'],
        section: 'source',
        sectionTitle: 'Source',
        order: 100,
        permission: 'sites.view',
        // Functions have no repository, build, deploy script or commands (docs/plans/FUNCTIONS.md).
        when: (ctx) => ctx.service.icon !== 'function',
        component: general('SourceSettings'),
    },
    {
        id: 'sites.compose',
        kinds: ['site'],
        section: 'compose',
        sectionTitle: 'Compose',
        order: 150,
        permission: 'sites.view',
        when: (ctx) => isCompose(ctx.service),
        component: lazy(() => import('./panel/compose/compose-settings').then((module) => ({ default: module.ComposeSettings }))),
    },
    {
        id: 'sites.build',
        kinds: ['site'],
        section: 'build',
        sectionTitle: 'Build',
        order: 200,
        permission: 'sites.view',
        // Functions have no repository, build, deploy script or commands (docs/plans/FUNCTIONS.md).
        when: (ctx) => ctx.service.icon !== 'function',
        component: general('BuildSettings'),
    },
    {
        id: 'sites.deploy-script',
        kinds: ['site'],
        section: 'deploy',
        sectionTitle: 'Deploy',
        order: 320,
        permission: 'sites.view',
        // Compose sites deploy with `docker compose up`; there is no deploy script.
        when: (ctx) => !isCompose(ctx.service) && ctx.service.icon !== 'function',
        component: lazy(() => import('./panel/settings/deploy-script').then((module) => ({ default: module.DeployScriptSettings }))),
    },
    {
        id: 'sites.shared-paths',
        kinds: ['site'],
        section: 'deploy',
        sectionTitle: 'Deploy',
        order: 330,
        permission: 'sites.view',
        when: (ctx) => !isCompose(ctx.service) && ctx.service.icon !== 'function',
        component: general('SharedPathsSettings'),
    },
    {
        id: 'sites.test-domain',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 420,
        permission: 'sites.view',
        component: general('TestDomainSettings'),
    },
    {
        id: 'sites.servers',
        kinds: ['site'],
        section: 'servers',
        sectionTitle: 'Servers',
        order: 500,
        permission: 'sites.view',
        component: general('ServersSettings'),
    },
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
        // Functions have no repository, build, deploy script or commands (docs/plans/FUNCTIONS.md).
        when: (ctx) => ctx.service.icon !== 'function',
        component: lazy(() => import('./panel/settings/commands').then((module) => ({ default: module.CommandsSettings }))),
    },
    {
        id: 'sites.danger',
        kinds: ['site'],
        section: 'danger',
        sectionTitle: 'Danger',
        order: 900,
        permission: 'sites.delete',
        component: general('DangerSettings'),
    },
);

// Organization settings → Compose policy (docs/COMPOSE_TEMPLATES.md §1.3).
registerSettingsNav({
    id: 'compose',
    title: 'Compose',
    url: '/settings/compose',
    group: 'organization',
    order: 150,
    icon: Boxes,
    permission: 'sites.view',
    requiresOrganization: true,
    keywords: ['docker', 'compose', 'privileged', 'policy', 'capabilities'],
});

interface SiteSearchResult {
    id: string;
    name: string;
    slug: string;
    runtime: string;
    repository: string | null;
}

registerCommands({
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
                title: `${site.name}: variables`,
                group: 'Sites',
                href: `/sites/${site.id}/environment`,
                keywords: [site.slug, 'env', 'variables', query],
            },
        ]);
    },
});
