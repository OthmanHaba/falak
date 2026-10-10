import { registerServerSections, registerServiceSettingsSections } from '@/lib/registry';
import { lazy } from 'react';

const SiteLimitsSection = lazy(() => import('./site-limits-section').then((module) => ({ default: module.SiteLimitsSection })));
const CapacityCard = lazy(() => import('./capacity-card').then((module) => ({ default: module.CapacityCard })));

// Settings → Resources: the site's limits, or each compose service's (functions have their own settings).
registerServiceSettingsSections({
    id: 'limits.site',
    kinds: ['site'],
    section: 'resources',
    sectionTitle: 'Resources',
    order: 550,
    permission: 'sites.view',
    when: (ctx) => ctx.service.icon !== 'function',
    component: SiteLimitsSection,
});

// Server page: what its services may use against what it has.
registerServerSections({
    id: 'limits.capacity',
    order: 100,
    permission: 'servers.view',
    component: CapacityCard,
});
