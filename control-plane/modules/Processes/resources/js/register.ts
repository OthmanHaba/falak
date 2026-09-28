import { toast } from '@/components/kiln';
import { requestJson } from '@/lib/http';
import { registerServiceActions, registerServiceSettingsSections, registerServiceTabs } from '@/lib/registry';
import { RefreshCcw } from 'lucide-react';
import { lazy } from 'react';

const ProcessesTab = lazy(() => import('./panel/processes-tab').then((module) => ({ default: module.ProcessesTab })));
const OctaneSection = lazy(() => import('./panel/octane-section').then((module) => ({ default: module.OctaneSection })));

// Canvas service panel (docs/UI_DESIGN.md §5.1): Processes 600.
registerServiceTabs({ id: 'processes', kinds: ['site'], title: 'Processes', order: 600, permission: 'processes.view', component: ProcessesTab });

// Settings → Laravel: Octane server, port and per-server routing state (after the Sites toggles, 600).
registerServiceSettingsSections({
    id: 'processes.octane',
    kinds: ['site'],
    section: 'laravel',
    sectionTitle: 'Laravel',
    order: 610,
    permission: 'processes.view',
    when: (ctx) => ctx.service.icon === 'laravel',
    component: OctaneSection,
});

// Service panel `⋯` → Restart processes (web process, workers, daemons of the site on every server).
registerServiceActions({
    id: 'processes.restart',
    kinds: ['site'],
    label: 'Restart processes',
    icon: RefreshCcw,
    order: 200,
    permission: 'processes.manage',
    perform: async (ctx) => {
        const body = await requestJson<{ data: { commands: number } } | null>(`/sites/${ctx.service.ref_id}/processes/restart`, 'POST', {});
        if (body?.data.commands === 0) {
            toast.info('Nothing to restart', 'No process runs for this site yet.');

            return;
        }
        toast.success(
            'Restarting processes',
            `${ctx.service.name} on ${ctx.service.servers.map((server) => server.name).join(', ') || 'its servers'}`,
        );
    },
});
