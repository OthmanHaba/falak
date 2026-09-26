import { registerCommands, registerNavigation } from '@/lib/registry';
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
        {
            id: 'databases.storage',
            title: 'Backup storage providers',
            group: 'Navigation',
            icon: HardDrive,
            href: '/databases/storage',
            permission: 'databases.view',
            keywords: ['s3', 'r2', 'b2', 'spaces', 'minio', 'bucket'],
        },
    ],
});
