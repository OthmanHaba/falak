import { registerCommands, registerNavigation, registerSettingsNav } from '@/lib/registry';
import { Archive, Database, HardDrive } from 'lucide-react';

registerNavigation({ id: 'databases', title: 'Databases', url: '/databases', icon: Database, order: 300, permission: 'databases.view' });

registerCommands({
    id: 'databases.navigation',
    commands: () => [
        { id: 'databases.index', title: 'Databases', group: 'Navigation', icon: Database, href: '/databases', permission: 'databases.view' },
        {
            id: 'databases.backups',
            title: 'Database backups',
            group: 'Navigation',
            icon: Archive,
            href: '/databases/backups',
            permission: 'databases.view',
            keywords: ['restore', 'dump'],
        },
    ],
});

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'storage',
    title: 'Backup storage',
    url: '/settings/storage',
    group: 'integrations',
    order: 220,
    icon: HardDrive,
    permission: 'databases.view',
    requiresOrganization: true,
    keywords: ['s3', 'r2', 'b2', 'spaces', 'minio', 'bucket', 'backups'],
});
