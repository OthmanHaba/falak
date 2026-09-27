import { registerCommands, registerSettingsNav } from '@/lib/registry';
import { GitBranch, Plus } from 'lucide-react';

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'source-control',
    title: 'Source control',
    url: '/settings/source-control',
    group: 'integrations',
    order: 200,
    icon: GitBranch,
    permission: 'source_control.view',
    requiresOrganization: true,
    keywords: ['git', 'github', 'gitlab', 'bitbucket', 'repositories', 'webhooks'],
});

registerCommands({
    id: 'source-control.actions',
    commands: () => [
        {
            id: 'source-control.connect',
            title: 'Connect a git provider',
            group: 'Actions',
            icon: Plus,
            href: '/settings/source-control',
            permission: 'source_control.manage',
            keywords: ['github', 'gitlab', 'bitbucket', 'oauth'],
        },
    ],
});
