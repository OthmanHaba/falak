import { registerCommands, registerSettingsNav } from '@/lib/registry';
import { type SharedData } from '@/types';
import { GitPullRequest } from 'lucide-react';

registerSettingsNav({
    id: 'previews',
    title: 'Previews',
    url: '/settings/previews',
    group: 'integrations',
    order: 260,
    icon: GitPullRequest,
    requiresOrganization: true,
    keywords: ['pull request', 'preview domain', 'wildcard', 'review apps'],
});

registerCommands({
    id: 'previews.navigation',
    commands: ({ props }: { props: SharedData }) => {
        const project = props.falak?.projects.find((item) => item.id === props.falak?.current.project_id);

        return project
            ? [
                  {
                      id: 'previews.project',
                      title: `${project.name}: previews`,
                      group: 'Projects',
                      icon: GitPullRequest,
                      href: `/projects/${project.id}/previews`,
                      keywords: ['pull request', 'merge request', 'preview', 'review app'],
                      permission: 'previews.view',
                  },
              ]
            : [];
    },
});
