import { toast } from '@/components/kiln';
import { copyText } from '@/components/kiln/copy-button';
import { registerCommands, registerServiceActions, registerServiceTabs } from '@/lib/registry';
import { type SharedData } from '@/types';
import { Copy, ExternalLink, Plus, Settings, Trash2 } from 'lucide-react';
import { lazy } from 'react';
import { pendingTab } from './components/pending-tab';
import { CREATE_SERVICE_EVENT } from './types';

const DeleteServiceDialog = lazy(() => import('./components/delete-service-dialog').then((module) => ({ default: module.DeleteServiceDialog })));

const onCanvas = () => /^\/projects\/[0-9A-Za-z]{26}\/(?!settings(\/|$))[^/]+/.test(window.location.pathname);

registerCommands({
    id: 'projects.canvas',
    commands: ({ props }: { props: SharedData }) => {
        const project = props.kiln?.projects.find((item) => item.id === props.kiln?.current.project_id);

        return [
            ...(onCanvas()
                ? [
                      {
                          id: 'projects.create-service',
                          title: 'Create service',
                          subtitle: 'Git repository, Docker image, database…',
                          group: 'Actions',
                          icon: Plus,
                          keywords: ['new', 'deploy', 'site', 'database', 'repository', 'docker'],
                          perform: () => window.dispatchEvent(new Event(CREATE_SERVICE_EVENT)),
                          permission: 'projects.manage',
                      },
                  ]
                : []),
            ...(project
                ? [
                      {
                          id: 'projects.settings',
                          title: `${project.name}: settings`,
                          group: 'Projects',
                          icon: Settings,
                          href: `/projects/${project.id}/settings`,
                          keywords: ['environments', 'rename', 'delete'],
                      },
                      {
                          id: 'projects.new-environment',
                          title: `${project.name}: new environment`,
                          group: 'Actions',
                          icon: Plus,
                          href: `/projects/${project.id}/settings#new-environment`,
                          keywords: ['staging', 'duplicate'],
                          permission: 'projects.manage',
                      },
                  ]
                : []),
        ];
    },
});

// Header actions every service has.
registerServiceActions(
    {
        id: 'projects.open-site',
        kinds: ['site'],
        label: 'Open site',
        icon: ExternalLink,
        order: 700,
        separated: true,
        when: (ctx) => Boolean(ctx.service.url),
        perform: (ctx) => void window.open(ctx.service.url ?? '', '_blank', 'noopener'),
    },
    {
        id: 'projects.copy-id',
        kinds: ['site', 'database'],
        label: 'Copy id',
        icon: Copy,
        order: 710,
        perform: async (ctx) => {
            if (await copyText(ctx.service.ref_id)) toast.success('Id copied', ctx.service.ref_id);
            else toast.error('Could not copy');
        },
    },
);

// Stand-ins until the owning modules ship these tabs (their registration replaces these).
registerServiceTabs(
    pendingTab({
        id: 'settings',
        title: 'Settings',
        order: 900,
        kinds: ['site'],
        module: 'Sites',
        permission: 'sites.view',
        description: 'Source, build, deploy, networking, servers, Laravel and commands.',
        legacy: '/sites/{id}/settings',
    }),
    pendingTab({
        id: 'metrics',
        title: 'Metrics',
        order: 400,
        kinds: ['database'],
        module: 'Telemetry',
        description: 'Connections, queries per second, cache hit rate and disk usage.',
    }),
);

registerServiceActions({
    id: 'projects.delete',
    kinds: ['site', 'database'],
    label: 'Delete',
    icon: Trash2,
    order: 900,
    danger: true,
    separated: true,
    permission: 'projects.manage',
    dialog: DeleteServiceDialog,
});
