import { registerSiteTabs } from '@/lib/registry';

registerSiteTabs(
    { id: 'deployments.deployments', title: 'Deployments', path: 'deployments', order: 50, permission: 'deployments.view' },
    { id: 'deployments.releases', title: 'Releases', path: 'releases', order: 60, permission: 'deployments.view' },
    { id: 'deployments.settings', title: 'Deploy settings', path: 'deploy-settings', order: 850, permission: 'deployments.view' },
);
