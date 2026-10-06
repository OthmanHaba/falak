import { registerCommands, registerSettingsNav } from '@/lib/registry';
import { type SharedData } from '@/types';
import { KeyRound } from 'lucide-react';

// Organization settings: the organization's own secrets (inherited by every project).
registerSettingsNav({
    id: 'secrets',
    title: 'Secrets',
    url: '/settings/secrets',
    group: 'organization',
    order: 150,
    icon: KeyRound,
    permission: 'secrets.view',
    requiresOrganization: true,
    keywords: ['secret', 'api key', 'password', 'vault', 'credentials', 'env'],
});

registerCommands({
    id: 'secrets.navigation',
    commands: ({ props }: { props: SharedData }) => {
        const project = props.falak?.projects.find((item) => item.id === props.falak?.current.project_id);

        return [
            ...(project
                ? [
                      {
                          id: 'secrets.project',
                          title: `${project.name}: secrets`,
                          group: 'Projects',
                          icon: KeyRound,
                          href: `/projects/${project.id}/settings/secrets`,
                          keywords: ['secret', 'api key', 'password', 'rotate'],
                          permission: 'secrets.view',
                      },
                  ]
                : []),
            {
                id: 'secrets.organization',
                title: 'Organization secrets',
                group: 'Navigation',
                icon: KeyRound,
                href: '/settings/secrets',
                permission: 'secrets.view',
            },
        ];
    },
});
