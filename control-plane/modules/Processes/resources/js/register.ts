import { registerSiteTabs } from '@/lib/registry';

registerSiteTabs(
    { id: 'processes.queues', title: 'Queues', path: 'queues', order: 450, permission: 'processes.view' },
    { id: 'processes.daemons', title: 'Daemons', path: 'daemons', order: 460, permission: 'processes.view' },
    { id: 'processes.scheduler', title: 'Scheduler', path: 'scheduler', order: 470, permission: 'processes.view' },
);
