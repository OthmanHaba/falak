/** Runtime-agnostic environment access (Node, Bun, Deno). */

type DenoLike = { env: { toObject(): Record<string, string> } };

let cached: Record<string, string | undefined> | undefined;

export function env(): Record<string, string | undefined> {
  if (cached) return cached;

  const proc = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process;
  if (proc?.env) return proc.env;

  const deno = (globalThis as { Deno?: DenoLike }).Deno;
  try {
    if (deno) return (cached = deno.env.toObject());
  } catch {
    // --allow-env not granted
  }

  return (cached = {});
}

export type Runtime = 'node' | 'bun' | 'deno' | 'unknown';

export function runtime(): Runtime {
  const g = globalThis as { Bun?: unknown; Deno?: unknown; process?: { versions?: { node?: string } } };
  if (g.Bun !== undefined) return 'bun';
  if (g.Deno !== undefined) return 'deno';
  if (g.process?.versions?.node) return 'node';
  return 'unknown';
}
