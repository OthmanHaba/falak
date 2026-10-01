// Loads the function's entrypoint (/app/$KILN_ENTRYPOINT) for kiln-fn-serve and kiln-fn-run, in every JS runtime.
import { join } from "node:path";
import { pathToFileURL } from "node:url";

const env = globalThis.process?.env ?? {};
export const entry = env.KILN_ENTRYPOINT ?? "index.ts";

/** Imports the entrypoint, or exits with the error (the gateway reports the last lines of output). */
export async function loadEntry() {
  try {
    return await import(pathToFileURL(join(globalThis.process?.cwd?.() ?? "/app", entry)).href);
  } catch (err) {
    console.error(`kiln: failed to load ${entry}:`, err);
    globalThis.process.exit(1);
  }
}

/**
 * The fetch handler of the default export: a Hono app (or anything with fetch(request)), a { fetch, websocket }
 * object, or a plain fetch(request) function.
 *
 * @returns {{ fetch: (req: Request, server?: unknown) => any, app: any, websocket?: unknown }}
 */
export function fetchHandler(mod) {
  const def = mod.default;
  if (def && typeof def.fetch === "function") return { fetch: def.fetch.bind(def), app: def, websocket: def.websocket };
  if (typeof def === "function") return { fetch: def, app: def };
  console.error(`kiln: ${entry} must \`export default\` a Hono app, an object with fetch(request), or a fetch(request) function`);
  globalThis.process.exit(1);
}
