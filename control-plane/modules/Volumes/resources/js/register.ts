import { registerCommands, registerServiceSettingsSections } from '@/lib/registry';
import { type SharedData } from '@/types';
import { HardDrive } from 'lucide-react';
import { lazy } from 'react';

// Panel code loads with the canvas, not with every page.
const VolumesSettings = lazy(() => import('./panel/volumes-settings').then((module) => ({ default: module.VolumesSettings })));

// Settings tab of a site: its volumes (container services attach theirs here). Storage sits between Deploy and
// Networking; functions keep no data.
registerServiceSettingsSections({
    id: 'volumes.site',
    kinds: ['site'],
    section: 'storage',
    sectionTitle: 'Storage',
    order: 350,
    permission: 'volumes.view',
    when: (ctx) => ctx.service.icon !== 'function',
    component: VolumesSettings,
});

registerCommands({
    id: 'volumes.navigation',
    commands: ({ props }: { props: SharedData }) => {
        const project = props.falak?.projects.find((item) => item.id === props.falak?.current.project_id);

        return project
            ? [
                  {
                      id: 'volumes.project',
                      title: `${project.name}: volumes`,
                      group: 'Projects',
                      icon: HardDrive,
                      href: `/projects/${project.id}/volumes`,
                      keywords: ['volume', 'disk', 'storage', 'backup', 'files'],
                      permission: 'volumes.view',
                  },
              ]
            : [];
    },
});
