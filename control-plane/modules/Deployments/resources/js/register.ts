import { registerServiceActions, registerServiceTabs, registerSiteTabs } from '@/lib/registry';
import { Rocket, RotateCcw, RotateCw } from 'lucide-react';
import { lazy } from 'react';
import { deploy } from './panel/api';

// Panel code loads with the canvas, not with every page.
const DeploymentsTab = lazy(() => import('./panel/deployments-tab').then((module) => ({ default: module.DeploymentsTab })));
const RollbackDialog = lazy(() => import('./panel/rollback-dialog').then((module) => ({ default: module.RollbackDialog })));

registerSiteTabs(
    { id: 'deployments.deployments', title: 'Deployments', path: 'deployments', order: 50, permission: 'deployments.view' },
    { id: 'deployments.releases', title: 'Releases', path: 'releases', order: 60, permission: 'deployments.view' },
    { id: 'deployments.settings', title: 'Deploy settings', path: 'deploy-settings', order: 850, permission: 'deployments.view' },
);

// Canvas service panel (§5.1 / §5.2).
registerServiceTabs({
    id: 'deployments',
    kinds: ['site'],
    title: 'Deployments',
    order: 100,
    permission: 'deployments.view',
    component: DeploymentsTab,
});

registerServiceActions(
    {
        id: 'deployments.deploy',
        kinds: ['site'],
        label: 'Deploy',
        icon: Rocket,
        order: 0,
        primary: true,
        permission: 'deployments.create',
        perform: (ctx) => deploy(ctx),
    },
    {
        id: 'deployments.redeploy',
        kinds: ['site'],
        label: 'Redeploy',
        icon: RotateCw,
        order: 100,
        permission: 'deployments.create',
        when: (ctx) => Boolean(ctx.service.last_deployment?.commit),
        perform: (ctx) => deploy(ctx, { commit: ctx.service.last_deployment?.commit }),
    },
    {
        id: 'deployments.rollback',
        kinds: ['site'],
        label: 'Rollback…',
        icon: RotateCcw,
        order: 110,
        permission: 'deployments.rollback',
        dialog: RollbackDialog,
    },
);
