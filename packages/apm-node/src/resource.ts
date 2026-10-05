import type { Attributes } from '@opentelemetry/api';
import { env, runtime } from './env.js';
import type { ResolvedOptions } from './config.js';
import { VERSION } from './version.js';

/** Resource attributes from FALAK_* env (the agent sets them authoritatively as well). */
export function falakResourceAttributes(options: Pick<ResolvedOptions, 'serviceName' | 'resourceAttributes'>): Attributes {
  const e = env();
  const attrs: Attributes = {
    'service.name': options.serviceName ?? 'unknown_service',
    'deployment.environment.name': e.FALAK_ENVIRONMENT ?? e.NODE_ENV,
    'falak.org.id': e.FALAK_ORG_ID,
    'falak.site.id': e.FALAK_SITE_ID,
    'falak.server.id': e.FALAK_SERVER_ID,
    'falak.deployment.id': e.FALAK_DEPLOYMENT_ID,
    'falak.release.id': e.FALAK_RELEASE_ID,
    'process.runtime.name': runtime(),
    'telemetry.sdk.name': 'falak-apm-node',
    'telemetry.sdk.language': 'nodejs',
    'telemetry.sdk.version': VERSION,
    ...options.resourceAttributes,
  };

  for (const key of Object.keys(attrs)) {
    if (attrs[key] === undefined || attrs[key] === '') delete attrs[key];
  }

  return attrs;
}
