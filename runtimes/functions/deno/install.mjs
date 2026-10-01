// kiln-fn-install (Deno): resolves a function's dependencies into /app, so that serving needs no network and no
// writable cache outside /tmp (the release is mounted read-only).
//
// - The user's deno.json is kept; Kiln only adds `"nodeModulesDir": "auto"` (npm packages in /app/node_modules)
//   and `"vendor": true` (jsr: and https: modules in /app/vendor) when they are missing.
// - Without one, Kiln writes deno.json: with a package.json its dependencies are used; otherwise the imports of the
//   source files (shared/scan.mjs) become `"<pkg>": "npm:<pkg>"` entries, so `import { Hono } from "hono"` works.
// `deno install` then writes deno.lock, which the agent keeps per code version (the same code installs the same
// versions). The download cache lives in /cache (DENO_DIR, shared by every release on the server).
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { scanPackages } from "../shared/scan.mjs";

const app = Deno.cwd();
const entry = Deno.env.get("KILN_ENTRYPOINT") ?? "index.ts";
const config = join(app, "deno.json");

if (!existsSync(join(app, entry))) {
  console.error(`kiln: entrypoint ${entry} not found`);
  Deno.exit(1);
}

if (existsSync(join(app, "deno.jsonc"))) {
  console.log('kiln: using deno.jsonc as is (it needs "nodeModulesDir": "auto" and "vendor": true to run without network)');
} else if (existsSync(config)) {
  let json;
  try {
    json = JSON.parse(readFileSync(config, "utf8"));
  } catch (err) {
    console.error(`kiln: deno.json is not valid JSON: ${err.message}`);
    Deno.exit(1);
  }
  json.nodeModulesDir ??= "auto";
  json.vendor ??= true;
  writeFileSync(config, JSON.stringify(json, null, 2) + "\n");
} else {
  const json = { nodeModulesDir: "auto", vendor: true };
  if (!existsSync(join(app, "package.json"))) {
    const deps = scanPackages(app);
    json.imports = Object.fromEntries(deps.map((d) => [d, `npm:${d}`]));
    console.log(deps.length ? `kiln: dependencies ${deps.join(", ")}` : "kiln: no dependencies");
  }
  writeFileSync(config, JSON.stringify(json, null, 2) + "\n");
}

const args = ["install", "--entrypoint", entry];
if (existsSync(join(app, "deno.jsonc"))) args.splice(1, 0, "--config", join(app, "deno.jsonc"));
const { code } = await new Deno.Command("deno", {
  args,
  cwd: app,
  env: { DENO_DIR: Deno.env.get("KILN_DENO_CACHE") ?? "/cache/deno", NO_COLOR: "1" },
  stdout: "inherit",
  stderr: "inherit",
}).output();
if (code === 0) console.log("kiln: dependencies installed");
Deno.exit(code);
