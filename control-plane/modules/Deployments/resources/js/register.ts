import { registerServiceActions, registerServiceLayers, registerServiceSettingsSections, registerServiceTabs } from '@/lib/registry';
import { Rocket, RotateCcw, RotateCw } from 'lucide-react';
import { lazy } from 'react';
import { deploy } from './panel/api';

// Panel code loads with the canvas, not with every page.
const DeploymentsTab = lazy(() => import('./panel/deployments-tab').then((module) => ({ default: module.DeploymentsTab })));
const DeploymentPanel = lazy(() => import('./panel/deployment-panel').then((module) => ({ default: module.DeploymentPanel })));
const RollbackDialog = lazy(() => import('./panel/rollback-dialog').then((module) => ({ default: module.RollbackDialog })));

// Canvas service panel (§5.1 / §5.2).
registerServiceTabs({
    id: 'deployments',
    kinds: ['site'],
    title: 'Deployments',
    order: 100,
    permission: 'deployments.view',
    component: DeploymentsTab,
});

// §5.5 stacked deployment panel: "View logs" / `ctx.open('deployments', id)` / `?logs={id}&logs_tab=deploy`.
registerServiceLayers({
    id: 'deployment',
    kinds: ['site'],
    param: 'logs',
    fromTab: 'deployments',
    permission: 'deployments.view',
    label: (ctx, record) => `${ctx.service.name} deployment ${record.slice(-8).toLowerCase()}`,
    component: DeploymentPanel,
});

// Settings tab blocks (§5.1): push to deploy (Source), strategy / retention / health check and the deploy hook (Deploy).
const settings = () => import('./panel/settings');
registerServiceSettingsSections(
    {
        id: 'deployments.push-to-deploy',
        kinds: ['site'],
        section: 'source',
        sectionTitle: 'Source',
        order: 110,
        permission: 'deployments.view',
        // Functions have no repository, build, deploy script or commands (docs/plans/FUNCTIONS.md).
        when: (ctx) => ctx.service.icon !== 'function',
        component: lazy(() => settings().then((module) => ({ default: module.PushToDeploySettings }))),
    },
    {
        id: 'deployments.strategy',
        kinds: ['site'],
        section: 'deploy',
        sectionTitle: 'Deploy',
        order: 310,
        permission: 'deployments.view',
        // Functions have no repository, build, deploy script or commands (docs/plans/FUNCTIONS.md).
        when: (ctx) => ctx.service.icon !== 'function',
        component: lazy(() => settings().then((module) => ({ default: module.DeployStrategySettings }))),
    },
    {
        id: 'deployments.hook',
        kinds: ['site'],
        section: 'deploy',
        sectionTitle: 'Deploy',
        order: 340,
        permission: 'deployments.view',
        component: lazy(() => settings().then((module) => ({ default: module.DeployHookSettings }))),
    },
);

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
