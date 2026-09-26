import { registerCommands, registerNavigation } from '@/lib/registry';
import { GitBranch, Plus } from 'lucide-react';

registerNavigation({
    id: 'source-control',
    title: 'Source control',
    url: '/source-control',
    icon: GitBranch,
    order: 250,
    permission: 'source_control.view',
});

registerCommands({
    id: 'source-control.navigation',
    commands: () => [
        {
            id: 'source-control.index',
            title: 'Source control',
            group: 'Navigation',
            icon: GitBranch,
            href: '/source-control',
            permission: 'source_control.view',
            keywords: ['git', 'github', 'gitlab', 'bitbucket', 'repositories'],
        },
        {
            id: 'source-control.connect',
            title: 'Connect a git provider',
            group: 'Actions',
            icon: Plus,
            href: '/source-control',
            permission: 'source_control.manage',
            keywords: ['github', 'gitlab', 'bitbucket', 'oauth'],
        },
    ],
});
