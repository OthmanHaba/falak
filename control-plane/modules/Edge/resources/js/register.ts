import { registerSiteTabs } from '@/lib/registry';

registerSiteTabs(
    { id: 'edge.domains', title: 'Domains & TLS', path: 'domains', order: 100, permission: 'edge.view' },
    { id: 'edge.routing', title: 'Routing', path: 'routing', order: 200, permission: 'edge.view' },
);
