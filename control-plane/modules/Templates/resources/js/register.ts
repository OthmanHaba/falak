import { CREATE_SERVICE_EVENT, registerCommands, registerCreateOptions, registerServiceActions, registerSettingsNav } from '@/lib/registry';
import { BookmarkPlus, LayoutTemplate } from 'lucide-react';
import { lazy } from 'react';

const TemplateStep = lazy(() => import('./components/template-step').then((module) => ({ default: module.TemplateStep })));
const SaveAsTemplateDialog = lazy(() => import('./components/save-as-template-dialog').then((module) => ({ default: module.SaveAsTemplateDialog })));

const onCanvas = () => /^\/projects\/[0-9A-Za-z]{26}\/(?!settings(\/|$))[^/]+/.test(window.location.pathname);

// Canvas Create picker → Template (docs/COMPOSE_TEMPLATES.md §3).
registerCreateOptions({
    id: 'template',
    title: 'Template',
    description: 'One-click apps: n8n, Plausible, Ghost, Grafana…',
    icon: LayoutTemplate,
    order: 500,
    permission: 'templates.view',
    stepTitle: 'Deploy a template',
    wide: true,
    component: TemplateStep,
});

// Settings → Templates (organization templates).
registerSettingsNav({
    id: 'templates',
    title: 'Templates',
    url: '/settings/templates',
    group: 'organization',
    order: 175,
    icon: LayoutTemplate,
    permission: 'templates.view',
    requiresOrganization: true,
    keywords: ['compose', 'one-click', 'apps', 'import'],
});

registerCommands({
    id: 'templates.commands',
    commands: () => [
        {
            id: 'templates.deploy',
            title: 'Deploy template…',
            subtitle: 'n8n, Plausible, Ghost, Grafana…',
            group: 'Actions',
            icon: LayoutTemplate,
            keywords: ['template', 'compose', 'one-click', 'app', 'create'],
            permission: 'templates.view',
            // On a canvas: open the Create picker on the gallery; elsewhere: the /templates page.
            ...(onCanvas()
                ? { perform: () => window.dispatchEvent(new CustomEvent(CREATE_SERVICE_EVENT, { detail: { option: 'template' } })) }
                : { href: '/templates' }),
        },
        { id: 'templates.gallery', title: 'Templates', group: 'Navigation', icon: LayoutTemplate, href: '/templates', permission: 'templates.view' },
    ],
});

// Service panel ⋯ → Save as template, for compose sites.
registerServiceActions({
    id: 'templates.save-as-template',
    kinds: ['site'],
    label: 'Save as template',
    icon: BookmarkPlus,
    order: 720,
    permission: 'templates.manage',
    when: (ctx) => ctx.service.icon === 'compose' || /^compose\b/i.test(ctx.service.subtitle ?? ''),
    dialog: SaveAsTemplateDialog,
});
