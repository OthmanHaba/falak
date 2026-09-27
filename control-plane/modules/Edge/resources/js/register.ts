import { registerServiceSettingsSections } from '@/lib/registry';
import { lazy } from 'react';

// Settings tab → Networking (docs/UI_DESIGN.md §5.1): domains + TLS, certificates, edge servers, load balancer
// (410), then redirects, basic auth, headers and access limits (430). Sites adds the test domain toggle (420).
const networking = () => import('./panel/networking');

registerServiceSettingsSections(
    {
        id: 'edge.domains',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 410,
        permission: 'edge.view',
        component: lazy(() => networking().then((module) => ({ default: module.DomainsSettings }))),
    },
    {
        id: 'edge.routing',
        kinds: ['site'],
        section: 'networking',
        sectionTitle: 'Networking',
        order: 430,
        permission: 'edge.view',
        component: lazy(() => networking().then((module) => ({ default: module.RoutingSettings }))),
    },
);
