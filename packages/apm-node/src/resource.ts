import type { Attributes } from '@opentelemetry/api';
import { env, runtime } from './env.js';
import type { ResolvedOptions } from './config.js';
import { VERSION } from './version.js';

/** Resource attributes from KILN_* env (the agent sets them authoritatively as well). */
export function kilnResourceAttributes(options: Pick<ResolvedOptions, 'serviceName' | 'resourceAttributes'>): Attributes {
  const e = env();
  const attrs: Attributes = {
    'service.name': options.serviceName ?? 'unknown_service',
    'deployment.environment.name': e.KILN_ENVIRONMENT ?? e.NODE_ENV,
    'kiln.org.id': e.KILN_ORG_ID,
    'kiln.site.id': e.KILN_SITE_ID,
    'kiln.server.id': e.KILN_SERVER_ID,
    'kiln.deployment.id': e.KILN_DEPLOYMENT_ID,
    'kiln.release.id': e.KILN_RELEASE_ID,
    'process.runtime.name': runtime(),
    'telemetry.sdk.name': 'kiln-apm-node',
    'telemetry.sdk.language': 'nodejs',
    'telemetry.sdk.version': VERSION,
    ...options.resourceAttributes,
  };

  for (const key of Object.keys(attrs)) {
    if (attrs[key] === undefined || attrs[key] === '') delete attrs[key];
  }

  return attrs;
}
