// falak-fn-run: one scheduled run of a function, in every JS runtime. Loads /app/$FALAK_ENTRYPOINT and calls its
// scheduled handler:
//
//   export async function scheduled(event) { … }            // or
//   export default { fetch: app.fetch, scheduled(event) { … } }
//
// event = { name, schedule, cron, trigger: "cron" | "manual", scheduledTime }. Exit 0 when it resolves, 1 when it
// throws (the error and its stack are printed, and reported to Insights).
// Telemetry first, so fetch calls made by the run are traced.
import { flush, traceScheduled } from "./telemetry.mjs";
import { entry, loadEntry } from "./entry.mjs";

const env = globalThis.process.env;
const schedule = env.FALAK_SCHEDULE ?? "";
const name = env.FALAK_SCHEDULE_NAME || schedule;
const cron = env.FALAK_SCHEDULE_CRON ?? "";
const trigger = env.FALAK_TRIGGER === "manual" ? "manual" : "cron";

const mod = await loadEntry();
const def = mod.default;
const handler = typeof mod.scheduled === "function" ? mod.scheduled : def?.scheduled;
if (typeof handler !== "function") {
  console.error(`falak: ${entry} has no scheduled handler: add \`export async function scheduled(event) { … }\``);
  globalThis.process.exit(1);
}

const event = { name, schedule, cron, trigger, scheduledTime: Date.now() };
console.log(`falak: running ${name} (${trigger}${cron ? `, ${cron}` : ""})`);
const started = performance.now();
let code = 0;
try {
  await traceScheduled(name, cron, async () => handler.call(def, event));
  console.log(`falak: ${name} finished in ${Math.round(performance.now() - started)}ms`);
} catch (err) {
  console.error(`falak: ${name} failed:`, err);
  code = 1;
}
await flush();
globalThis.process.exit(code);
