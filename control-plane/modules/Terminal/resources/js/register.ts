import { registerCommands, registerNavigation } from '@/lib/registry';
import { SquareTerminal } from 'lucide-react';

registerNavigation({ id: 'terminal', title: 'Terminal', url: '/terminal', icon: SquareTerminal, order: 600, permission: 'terminal.open' });

registerCommands({
    id: 'terminal.navigation',
    commands: () => [
        {
            id: 'terminal.index',
            title: 'Terminal sessions',
            group: 'Navigation',
            icon: SquareTerminal,
            href: '/terminal',
            keywords: ['shell', 'ssh', 'console', 'recording'],
            permission: 'terminal.open',
        },
    ],
});
