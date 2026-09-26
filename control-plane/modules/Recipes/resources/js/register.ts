import { registerCommands, registerNavigation } from '@/lib/registry';
import { History, Plus, ScrollText } from 'lucide-react';

registerNavigation({ id: 'recipes', title: 'Recipes', url: '/recipes', icon: ScrollText, order: 500, permission: 'recipes.view' });

registerCommands({
    id: 'recipes.navigation',
    commands: () => [
        {
            id: 'recipes.index',
            title: 'Recipes',
            group: 'Navigation',
            icon: ScrollText,
            href: '/recipes',
            permission: 'recipes.view',
            keywords: ['scripts'],
        },
        { id: 'recipes.runs', title: 'Recipe run history', group: 'Navigation', icon: History, href: '/recipes/runs', permission: 'recipes.view' },
        {
            id: 'recipes.create',
            title: 'Create recipe',
            group: 'Actions',
            icon: Plus,
            href: '/recipes?create=1',
            permission: 'recipes.manage',
            keywords: ['script'],
        },
    ],
});
