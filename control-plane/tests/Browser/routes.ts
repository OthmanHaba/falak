/**
 * Every route the browser smoke test visits (docs/UI_DESIGN.md §8). Extend this list when you add pages.
 *
 * - `path` may contain `:param` placeholders; each param is resolved once per run by opening `params[name].from`
 *   and taking the first link whose pathname matches `params[name].match` (capture group 1 = the value).
 *   Routes whose params can't be resolved (empty list) are skipped, not failed.
 * - `allowedFailures` lists request URL patterns that may legitimately return 4xx/5xx on that page
 *   (e.g. an observability backend that isn't running locally).
 * - `guest: true` visits the page logged out.
 */
export interface BrowserRoute {
    path: string;
    guest?: boolean;
    allowedFailures?: RegExp[];
    /** Extra console messages to ignore on this page. */
    allowedConsole?: RegExp[];
}

export interface ParamSource {
    from: string;
    match: RegExp;
}

const ULID = '([0-9A-Za-z]{26})';

export const params: Record<string, ParamSource> = {
    site: { from: '/sites', match: new RegExp(`^/sites/${ULID}$`) },
    server: { from: '/servers', match: new RegExp(`^/servers/${ULID}$`) },
};

/** Requests allowed to fail on every page (optional backends in local/sim setups). */
export const globalAllowedFailures: RegExp[] = [/\/broadcasting\/auth/, /\/favicon\.ico$/];

/** Console noise allowed everywhere (realtime transport retries when Reverb isn't running). */
export const globalAllowedConsole: RegExp[] = [/WebSocket connection to .* failed/i, /pusher/i];

const observability = [/\/telemetry\/.*\/data/, /\/telemetry\/.*search/, /\/insights\/.*\/data/];

export const routes: BrowserRoute[] = [
    // Auth (guest)
    { path: '/login', guest: true },
    { path: '/register', guest: true },
    { path: '/forgot-password', guest: true },
    { path: '/', guest: true },

    // Home + shell
    { path: '/dashboard' },
    { path: '/organizations/create' },
    { path: '/notifications' },

    // Settings shell
    { path: '/settings/profile' },
    { path: '/settings/password' },
    { path: '/settings/two-factor' },
    { path: '/settings/appearance' },
    { path: '/settings/api-tokens' },
    { path: '/settings/organization' },
    { path: '/settings/members' },
    { path: '/settings/teams' },
    { path: '/settings/audit-log' },

    // Infrastructure
    { path: '/servers' },
    { path: '/servers/create' },
    { path: '/servers/:server' },
    { path: '/telemetry/servers/:server/metrics', allowedFailures: observability },
    { path: '/ssh-keys' },
    { path: '/providers' },
    { path: '/network' },
    { path: '/network/servers/:server/firewall' },
    { path: '/terminal' },
    { path: '/recipes' },
    { path: '/recipes/runs' },

    // Sites (until the project canvas replaces them)
    { path: '/sites' },
    { path: '/sites/create' },
    { path: '/sites/:site' },
    { path: '/sites/:site/environment' },
    { path: '/sites/:site/deploy-script' },
    { path: '/sites/:site/commands' },
    { path: '/sites/:site/settings' },
    { path: '/sites/:site/domains' },
    { path: '/sites/:site/routing' },
    { path: '/sites/:site/deployments' },
    { path: '/sites/:site/releases' },
    { path: '/sites/:site/deploy-settings' },
    { path: '/sites/:site/queues' },
    { path: '/sites/:site/daemons' },
    { path: '/sites/:site/scheduler' },

    // Data, builds, source control
    { path: '/databases' },
    { path: '/databases/backups' },
    { path: '/databases/storage' },
    { path: '/builds' },
    { path: '/builds/builders' },
    { path: '/source-control' },

    // Observability
    { path: '/insights', allowedFailures: observability },
    { path: '/insights/issues' },
    { path: '/insights/heartbeats' },
    { path: '/telemetry/logs', allowedFailures: observability },
    { path: '/telemetry/traces', allowedFailures: observability },
    { path: '/telemetry/settings' },
    { path: '/alerting/rules' },
    { path: '/alerting/channels' },
    { path: '/alerting/history' },
];

export function slugFor(path: string): string {
    return path.replace(/^\//, '').replace(/[/:]+/g, '-').replace(/[^a-z0-9-]/gi, '') || 'root';
}
