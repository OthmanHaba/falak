import { registerCommands } from '@/lib/registry';
import { ShieldCheck } from 'lucide-react';

// The overview lives under Infrastructure (/security); each server has a Security tab.
registerCommands({
    id: 'security.navigation',
    commands: () => [
        {
            id: 'security.index',
            title: 'Security baseline',
            group: 'Navigation',
            icon: ShieldCheck,
            href: '/security',
            permission: 'security.view',
            keywords: ['security', 'audit', 'hardening', 'score', 'production ready'],
        },
    ],
});
