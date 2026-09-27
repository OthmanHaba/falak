import { registerCommands, registerSettingsNav } from '@/lib/registry';
import { History, Plus, ScrollText } from 'lucide-react';

// Organization settings → the org-wide script library (docs/UI_DESIGN.md §3). Runs stay under /recipes/runs.
registerSettingsNav({
    id: 'recipes',
    title: 'Recipes',
    url: '/settings/recipes',
    group: 'organization',
    order: 170,
    icon: ScrollText,
    permission: 'recipes.view',
    requiresOrganization: true,
    keywords: ['scripts', 'bash', 'library'],
});

registerCommands({
    id: 'recipes.navigation',
    commands: () => [
        { id: 'recipes.runs', title: 'Recipe run history', group: 'Navigation', icon: History, href: '/recipes/runs', permission: 'recipes.view' },
        {
            id: 'recipes.create',
            title: 'Create recipe',
            group: 'Actions',
            icon: Plus,
            href: '/settings/recipes?create=1',
            permission: 'recipes.manage',
            keywords: ['script'],
        },
    ],
});
