import { registerCommands, registerServerSections, registerSettingsNav, registerShellBanners } from '@/lib/registry';
import { LifeBuoy, ShieldCheck } from 'lucide-react';
import { lazy } from 'react';
import { DisasterRecoveryBanner } from './components/dr-banner';
import { disasterRecoveryOf } from './types';

const ServerRecoveryCard = lazy(() => import('./components/server-recovery-card').then((module) => ({ default: module.ServerRecoveryCard })));

// Until the control plane's DR is configured: a banner for the install's owners and admins (the shared prop is only
// theirs; it renders nothing for everyone else or once dismissed).
registerShellBanners({ id: 'recovery.control-plane', order: 100, permission: 'recovery.control_plane', component: DisasterRecoveryBanner });

// Organization settings → Disaster recovery: only in the install's operator organization.
registerSettingsNav({
    id: 'disaster-recovery',
    title: 'Disaster recovery',
    url: '/settings/disaster-recovery',
    group: 'organization',
    order: 180,
    icon: LifeBuoy,
    permission: 'recovery.control_plane',
    requiresOrganization: true,
    when: (ctx) => disasterRecoveryOf(ctx.props) !== null,
    keywords: ['backup', 'restore', 'dr', 'drill', 's3', 'control plane'],
});

registerServerSections({ id: 'recovery.server', order: 900, permission: 'recovery.servers', component: ServerRecoveryCard });

registerCommands({
    id: 'recovery.navigation',
    commands: () => [
        {
            id: 'recovery.readiness',
            title: 'Disaster recovery readiness',
            group: 'Navigation',
            icon: ShieldCheck,
            href: '/recovery/readiness',
            permission: 'recovery.view',
            keywords: ['dr', 'backups', 'pitr'],
        },
    ],
});
