#!/usr/bin/env node
/**
 * FORUM-IDENTITY-002-R3 — Flarum 1.8.19 Frontend::js() CommonJS wrapper boot gate.
 *
 * Each Extend\Frontend('forum')->js() file is compiled as:
 *
 *   var module={};
 *   <file>
 *   flarum.extensions['<id>']=module.exports;
 *
 * Application.tsx:344 then does `if (!extension.extend) return`.
 * If module.exports is never assigned, extension is undefined and the SPA
 * throws: Cannot read properties of undefined (reading 'extend').
 */

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const MODULE_NAME = "flatrate-wiki-supabase-oauth";

const EXISTING_ASSETS = [
  "js/dist/forum-navigation.js",
  "js/dist/forum.js",
  "js/dist/mobile-brand-drawer.js",
];

function text(rel) {
  return readFileSync(join(ROOT, rel), "utf8");
}

function wrapFrontendJs(contents, moduleName) {
  return (
    "var module={};\n" +
    contents +
    "\nflarum.extensions['" +
    moduleName +
    "']=module.exports;\n"
  );
}

function compileAssets(relPaths) {
  return relPaths.map((rel) => wrapFrontendJs(text(rel), MODULE_NAME)).join("\n");
}

/**
 * Mirrors Flarum 1.8.19 Application.bootExtensions
 * (framework/core/js/src/common/Application.tsx:339-351).
 */
function bootExtensions(extensions) {
  Object.keys(extensions).forEach((name) => {
    const extension = extensions[name];
    if (!extension.extend) return;
    const extenders = extension.extend.flat(Infinity);
    for (const extender of extenders) {
      extender.extend({}, { name, exports: extension });
    }
  });
}

function runCompiled(compiled) {
  const sandbox = {
    app: {
      initializers: {
        add() {},
      },
    },
    flarum: {
      extensions: {},
      core: { compat: {} },
      reg: { get() { return null; } },
    },
    m() {
      return null;
    },
    console,
  };
  sandbox.globalThis = sandbox;
  sandbox.window = sandbox;
  const context = createContext(sandbox);
  runInContext(compiled, context);
  return sandbox.flarum.extensions;
}

function bootError(relPaths) {
  const extensions = runCompiled(compileAssets(relPaths));
  try {
    bootExtensions(extensions);
    return { error: null, exportValue: extensions[MODULE_NAME] };
  } catch (error) {
    return { error, exportValue: extensions[MODULE_NAME] };
  }
}

test("Flarum 1.8.19 Frontend.php still assigns module.exports after var module={}", () => {
  const fixture = text("test/fixtures/flarum-1.8.19-Extend-Frontend.php");
  assert.match(fixture, /return 'var module=\{\};'/);
  assert.match(
    fixture,
    /flarum\.extensions\['\$moduleName'\]=module\.exports;/,
  );
});

test("CASE_B: bbc9a62 without member-display registration boots", () => {
  const result = bootError(EXISTING_ASSETS);
  assert.equal(result.error, null, String(result.error && result.error.stack));
  assert.equal(typeof result.exportValue, "object");
  assert.notEqual(result.exportValue, null);
  console.error("CASE_B=PASS");
});

test("CASE_A / OLD_BBC9A62: last-file missing module.exports reproduces Application.tsx:344", () => {
  const result = bootError([
    ...EXISTING_ASSETS,
    "test/fixtures/bbc9a62-member-display.js",
  ]);
  assert.ok(result.error, "expected bbc9a62 last-file wrapper to throw");
  assert.match(result.error.message, /reading 'extend'/);
  assert.equal(result.exportValue, undefined);
  console.error("CASE_A=FAIL");
  console.error("OLD_BBC9A62_SPA_BOOT=FAIL");
  console.error("SPA_FAILURE_REPRODUCED=true");
  console.error("SPA_FAILURE_ERROR=" + result.error.message);
  console.error("MEMBER_DISPLAY_ASSET_CAUSAL=true");
});

test("candidate member-display.js is last and must assign module.exports", () => {
  const result = bootError([...EXISTING_ASSETS, "js/dist/member-display.js"]);
  if (result.error) {
    console.error("NEW_CANDIDATE_SPA_BOOT=FAIL");
    console.error("CANDIDATE_BOOT_ERROR=" + result.error.message);
  } else {
    console.error("NEW_CANDIDATE_SPA_BOOT=PASS");
  }
  assert.equal(result.error, null, String(result.error && result.error.stack));
  assert.equal(typeof result.exportValue, "object");
  assert.notEqual(result.exportValue, null);
  assert.equal(result.exportValue.extend, undefined);
});
