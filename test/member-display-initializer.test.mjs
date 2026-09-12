#!/usr/bin/env node
/**
 * Execute the member-display initializer against a Flarum 1.8-shaped registry.
 * The helper-only VM test never reaches this path.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const source = readFileSync(join(ROOT, "js/dist/member-display.js"), "utf8");

function SettingsPage() {}
SettingsPage.prototype.oninit = function () {};
SettingsPage.prototype.settingsItems = function () {};

function loadWithReg() {
  const calls = [];
  const initializers = {};
  const sandbox = {
    app: {
      initializers: {
        add(name, fn) {
          initializers[name] = fn;
        },
      },
      translator: {
        trans(key) {
          return key;
        },
      },
      session: { user: null },
      forum: {
        attribute() {
          return "/api";
        },
      },
      request() {
        return Promise.resolve({});
      },
    },
    flarum: {
      extensions: {},
      reg: {
        get(ext, id) {
          if (ext === "core" && id === "common/extend") {
            return {
              extend(object, method, callback) {
                calls.push({ object, method, callback });
              },
            };
          }
          if (ext === "core" && id === "forum/components/SettingsPage") {
            return SettingsPage;
          }
          return null;
        },
      },
    },
    m() {
      return null;
    },
    module: { exports: undefined },
    console,
  };
  sandbox.globalThis = sandbox;
  runInContext(source, createContext(sandbox));
  return { sandbox, initializers, calls };
}

test("initializer registers and extends SettingsPage.prototype via flarum.reg", () => {
  const { sandbox, initializers, calls } = loadWithReg();
  assert.equal(typeof initializers["flatrate-wiki-member-display"], "function");
  initializers["flatrate-wiki-member-display"]();
  assert.deepEqual(
    calls.map((c) => c.method),
    ["oninit", "settingsItems"],
  );
  assert.equal(calls[0].object, SettingsPage.prototype);
  assert.equal(sandbox.module.exports && typeof sandbox.module.exports, "object");
  assert.equal(sandbox.module.exports.extend, undefined);
});

test("initializer extends SettingsPage via Flarum 1.8.19 flarum.core.compat", () => {
  const calls = [];
  const initializers = {};
  const sandbox = {
    app: {
      initializers: {
        add(name, fn) {
          initializers[name] = fn;
        },
      },
    },
    flarum: {
      core: {
        compat: {
          "common/extend": {
            extend(object, method, callback) {
              calls.push({ object, method, callback });
            },
          },
          "forum/components/SettingsPage": SettingsPage,
        },
      },
    },
    m() {
      return null;
    },
    module: { exports: undefined },
    console,
  };
  sandbox.globalThis = sandbox;
  runInContext(source, createContext(sandbox));
  initializers["flatrate-wiki-member-display"]();
  assert.deepEqual(
    calls.map((c) => c.method),
    ["oninit", "settingsItems"],
  );
  assert.equal(calls[0].object, SettingsPage.prototype);
});

test("initializer ignores bbc9a62 noncanonical compat keys", () => {
  const calls = [];
  const initializers = {};
  const sandbox = {
    app: {
      initializers: {
        add(name, fn) {
          initializers[name] = fn;
        },
      },
    },
    flarum: {
      core: {
        compat: {
          extend: {
            extend(object, method, callback) {
              calls.push({ object, method, callback });
            },
          },
          "flarum/common/extend": {
            extend(object, method, callback) {
              calls.push({ object, method, callback });
            },
          },
          "components/SettingsPage": SettingsPage,
          "flarum/forum/components/SettingsPage": SettingsPage,
        },
      },
    },
    m() {
      return null;
    },
    module: {},
    console,
  };
  sandbox.globalThis = sandbox;
  runInContext(source, createContext(sandbox));
  initializers["flatrate-wiki-member-display"]();
  assert.deepEqual(calls, []);
});

test("initializer no-ops when neither registry nor compat expose extend", () => {
  const initializers = {};
  const sandbox = {
    app: {
      initializers: {
        add(name, fn) {
          initializers[name] = fn;
        },
      },
    },
    flarum: {
      reg: {
        get() {
          return {};
        },
      },
    },
    m() {
      return null;
    },
    module: {},
    console,
  };
  sandbox.globalThis = sandbox;
  runInContext(source, createContext(sandbox));
  assert.doesNotThrow(() => initializers["flatrate-wiki-member-display"]());
});

test("source uses guarded registry/compat access and assigns module.exports", () => {
  assert.match(source, /flarum\.reg\.get\('core', id\)/);
  assert.match(source, /coreExport\('common\/extend'\)/);
  assert.match(source, /coreExport\('forum\/components\/SettingsPage'\)/);
  assert.match(source, /typeof extendModule\.extend === 'function'/);
  assert.match(source, /module\.exports = \{\}/);
  assert.doesNotMatch(source, /flarum\.reg\.get\([^)]+\)\.extend/);
  assert.match(source, /flarum\.core\.compat/);
});
