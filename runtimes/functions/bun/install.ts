// kiln-fn-install: resolves a function's npm dependencies into /app (node_modules + bun.lock).
//
// Without a package.json, the packages are read from the imports of the source files: every bare specifier that
// is not a Node/Bun builtin becomes a dependency on its latest version, pinned by the lockfile written into the
// release (so a rollback reuses exactly what was installed). The package cache lives in /cache (shared by every
// release on the server).
import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { builtinModules } from "node:module";
import { join, relative } from "node:path";

const app = process.cwd();
const entry = process.env.KILN_ENTRYPOINT ?? "index.ts";
const builtins = new Set(builtinModules);
const sources = /\.(m|c)?(t|j)sx?$/;

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    if (name === "node_modules" || name.startsWith(".")) continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...sourceFiles(p));
    else if (sources.test(name)) out.push(p);
  }
  return out;
}

// "hono/cors" → "hono", "@scope/pkg/x" → "@scope/pkg"; undefined for anything that is not an npm package.
export function packageOf(spec: string): string | undefined {
  if (/^(\.|\/|[a-z][a-z0-9+.-]*:)/i.test(spec) || spec === "bun" || builtins.has(spec) || builtins.has(spec.split("/")[0])) {
    return undefined;
  }
  const parts = spec.split("/");
  const name = spec.startsWith("@") ? parts.slice(0, 2).join("/") : parts[0];
  return /^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/.test(name) ? name : undefined;
}

if (!existsSync(join(app, entry))) {
  console.error(`kiln: entrypoint ${entry} not found`);
  process.exit(1);
}

if (!existsSync(join(app, "package.json"))) {
  const transpiler = new Bun.Transpiler({ loader: "tsx" });
  const deps = new Set<string>();
  for (const file of sourceFiles(app)) {
    let imports;
    try {
      imports = transpiler.scanImports(readFileSync(file, "utf8"));
    } catch (err) {
      console.error(`kiln: ${relative(app, file)}: ${(err as Error).message}`);
      process.exit(1);
    }
    for (const imp of imports) {
      const pkg = packageOf(imp.path);
      if (pkg) deps.add(pkg);
    }
  }
  const dependencies = Object.fromEntries([...deps].sort().map((d) => [d, "latest"]));
  writeFileSync(
    join(app, "package.json"),
    JSON.stringify({ name: "kiln-function", private: true, type: "module", dependencies }, null, 2) + "\n",
  );
  console.log(deps.size ? `kiln: dependencies ${[...deps].sort().join(", ")}` : "kiln: no dependencies");
}

const proc = Bun.spawnSync(["bun", "install", "--no-progress"], {
  cwd: app,
  stdout: "inherit",
  stderr: "inherit",
  env: { ...process.env, BUN_INSTALL_CACHE_DIR: process.env.BUN_INSTALL_CACHE_DIR ?? "/cache/bun" },
});
process.exit(proc.exitCode ?? 1);
