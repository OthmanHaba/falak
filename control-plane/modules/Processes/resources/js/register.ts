import { toast } from '@/components/kiln';
import { requestJson } from '@/lib/http';
import { registerServiceActions, registerSiteTabs } from '@/lib/registry';
import { RefreshCcw } from 'lucide-react';

registerSiteTabs(
    { id: 'processes.queues', title: 'Queues', path: 'queues', order: 450, permission: 'processes.view' },
    { id: 'processes.daemons', title: 'Daemons', path: 'daemons', order: 460, permission: 'processes.view' },
    { id: 'processes.scheduler', title: 'Scheduler', path: 'scheduler', order: 470, permission: 'processes.view' },
);

// Service panel `⋯` → Restart processes (web process, workers, daemons of the site on every server).
registerServiceActions({
    id: 'processes.restart',
    kinds: ['site'],
    label: 'Restart processes',
    icon: RefreshCcw,
    order: 200,
    permission: 'processes.manage',
    perform: async (ctx) => {
        await requestJson(`/sites/${ctx.service.ref_id}/processes/restart`, 'POST', {});
        toast.success(
            'Restarting processes',
            `${ctx.service.name} on ${ctx.service.servers.map((server) => server.name).join(', ') || 'its servers'}`,
        );
    },
});
