/**
 * Next.js helper. In `instrumentation.ts` at the project root:
 *
 *   export async function register() {
 *     const { registerKiln } = await import('@kiln/apm-node/next');
 *     await registerKiln();
 *   }
 *
 * Only the Node.js runtime is instrumented; the Edge runtime is skipped. Next.js emits its own
 * OpenTelemetry spans (route handlers, rendering, fetch) which Kiln maps and exports.
 */
import type { KilnOptions } from './config.js';
import { env } from './env.js';
import type { KilnHandle } from './sdk.js';

export async function registerKiln(options: KilnOptions = {}): Promise<KilnHandle | undefined> {
  if (env().NEXT_RUNTIME !== 'nodejs') return undefined;

  const { start } = await import('./sdk.js');

  // Next bundles its own http server; keep auto-instrumentation for outgoing calls and databases.
  return start({ serviceName: env().KILN_SERVICE_NAME ?? env().npm_package_name, ...options });
}

export { withKilnRequest, setUser } from './fetch.js';
export { recordException } from './exceptions.js';
