// The npm packages a function imports, for runtimes without a transpiler API (Node, Deno). Reads every source file
// under the app directory (skipping node_modules and dot directories) for static `import … from 'x'` /
// `export … from 'x'` / `import 'x'`, dynamic `import('x')` and `require('x')`, and keeps bare specifiers that are
// npm packages: "hono/cors" → "hono", "@scope/pkg/x" → "@scope/pkg". node:, bun:, npm:, jsr:, URL, relative and
// absolute specifiers and Node builtins are skipped.
import { readdirSync, readFileSync, statSync } from "node:fs";
import { builtinModules } from "node:module";
import { join } from "node:path";

const builtins = new Set(builtinModules);
const SOURCES = /\.(m|c)?(t|j)sx?$/;
const PATTERNS = [
  /(?:^|[^\w$.])(?:import|export)\s+(?:[^'"`;]*?\s+from\s+)?['"]([^'"\n]+)['"]/g,
  /(?:^|[^\w$.])import\s*\(\s*['"]([^'"\n]+)['"]\s*\)/g,
  /(?:^|[^\w$.])require\s*\(\s*['"]([^'"\n]+)['"]\s*\)/g,
];

/** @returns {string[]} */
export function sourceFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    if (name === "node_modules" || name.startsWith(".")) continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...sourceFiles(p));
    else if (SOURCES.test(name)) out.push(p);
  }
  return out;
}

/** The npm package of a specifier, or undefined when it is not one. */
export function packageOf(spec) {
  if (/^(\.|\/|[a-z][a-z0-9+.-]*:)/i.test(spec) || spec === "bun" || builtins.has(spec) || builtins.has(spec.split("/")[0])) {
    return undefined;
  }
  const parts = spec.split("/");
  const name = spec.startsWith("@") ? parts.slice(0, 2).join("/") : parts[0];
  return /^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/.test(name) ? name : undefined;
}

/** Specifiers imported by one source text (comments removed first). */
export function importsOf(source) {
  const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  const found = [];
  for (const re of PATTERNS) {
    for (const m of code.matchAll(re)) found.push(m[1]);
  }
  return found;
}

/** Sorted npm packages imported anywhere under dir. */
export function scanPackages(dir) {
  const deps = new Set();
  for (const file of sourceFiles(dir)) {
    for (const spec of importsOf(readFileSync(file, "utf8"))) {
      const pkg = packageOf(spec);
      if (pkg) deps.add(pkg);
    }
  }
  return [...deps].sort();
}
