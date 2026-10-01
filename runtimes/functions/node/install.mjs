// kiln-fn-install (Node): resolves a function's npm dependencies into /app (node_modules + package-lock.json).
//
// Without a package.json, the packages are read from the imports of the source files (shared/scan.mjs): every bare
// specifier that is not a Node builtin becomes a dependency on its latest version. The lockfile stays in the
// release, and the agent keeps it per code version, so the same code always installs the same versions (`npm ci`
// when a lockfile is there). The npm cache lives in /cache (shared by every release on the server).
import { spawnSync } from "node:child_process";
import { existsSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { scanPackages } from "../shared/scan.mjs";

const app = process.cwd();
const entry = process.env.KILN_ENTRYPOINT ?? "index.ts";

if (!existsSync(join(app, entry))) {
  console.error(`kiln: entrypoint ${entry} not found`);
  process.exit(1);
}

if (!existsSync(join(app, "package.json"))) {
  const deps = scanPackages(app);
  const dependencies = Object.fromEntries(deps.map((d) => [d, "latest"]));
  writeFileSync(join(app, "package.json"), JSON.stringify({ name: "kiln-function", private: true, type: "module", dependencies }, null, 2) + "\n");
  console.log(deps.length ? `kiln: dependencies ${deps.join(", ")}` : "kiln: no dependencies");
}

const ci = existsSync(join(app, "package-lock.json"));
const proc = spawnSync("npm", [ci ? "ci" : "install", "--no-audit", "--no-fund", "--omit=dev", "--loglevel=warn"], {
  cwd: app,
  stdio: "inherit",
  env: { ...process.env, npm_config_cache: process.env.npm_config_cache ?? "/cache/npm", npm_config_update_notifier: "false" },
});
if (proc.status === 0) console.log(ci ? "kiln: installed the locked versions" : "kiln: dependencies installed");
process.exit(proc.status ?? 1);
