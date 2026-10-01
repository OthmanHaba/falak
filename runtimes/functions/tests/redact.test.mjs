// node --test runtimes/functions/tests (also runs under `bun test` and `deno test --allow-read`).
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { redactPath, redactText, safeUrl } from "../shared/redact.mjs";

const cases = JSON.parse(readFileSync(new URL("./redact-cases.json", import.meta.url), "utf8"));

test("secret path segments are redacted", () => {
  for (const [input, want] of cases.paths) assert.equal(redactPath(input), want, input);
});

test("urls keep scheme, host and path only", () => {
  for (const [input, want] of cases.urls) assert.equal(safeUrl(new URL(input)), want, input);
});

test("urls quoted in error messages are made safe", () => {
  for (const [input, want] of cases.texts) assert.equal(redactText(input), want, input);
});
