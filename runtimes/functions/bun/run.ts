// kiln-fn-run: one scheduled run of a function. Loads /app/$KILN_ENTRYPOINT and calls its scheduled handler:
//
//   export async function scheduled(event) { … }            // or
//   export default { fetch: app.fetch, scheduled(event) { … } }
//
// event = { name, schedule, cron, trigger: "cron" | "manual", scheduledTime }. Exit 0 when it resolves, 1 when it
// throws (the error and its stack are printed, and reported to Insights).
import { join } from "node:path";
// First, so fetch calls made by the run are traced.
import { flush, traceScheduled } from "./telemetry.ts";

const entry = process.env.KILN_ENTRYPOINT ?? "index.ts";
const schedule = process.env.KILN_SCHEDULE ?? "";
const name = process.env.KILN_SCHEDULE_NAME || schedule;
const cron = process.env.KILN_SCHEDULE_CRON ?? "";
const trigger = process.env.KILN_TRIGGER === "manual" ? "manual" : "cron";

type ScheduledHandler = (event: unknown) => unknown;

let mod: Record<string, unknown>;
try {
  mod = await import(join(process.cwd(), entry));
} catch (err) {
  console.error(`kiln: failed to load ${entry}:`, err);
  process.exit(1);
}

const def = mod.default as { scheduled?: ScheduledHandler } | undefined;
const handler = (typeof mod.scheduled === "function" ? mod.scheduled : def?.scheduled) as ScheduledHandler | undefined;
if (typeof handler !== "function") {
  console.error(`kiln: ${entry} has no scheduled handler: add \`export async function scheduled(event) { … }\``);
  process.exit(1);
}

const event = { name, schedule, cron, trigger, scheduledTime: Date.now() };
console.log(`kiln: running ${name} (${trigger}${cron ? `, ${cron}` : ""})`);
const started = performance.now();
let code = 0;
try {
  await traceScheduled(name, cron, async () => handler.call(def, event));
  console.log(`kiln: ${name} finished in ${Math.round(performance.now() - started)}ms`);
} catch (err) {
  console.error(`kiln: ${name} failed:`, err);
  code = 1;
}
await flush();
process.exit(code);
