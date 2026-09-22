#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const source = readFileSync(join(ROOT, "js/dist/admin-gamification.js"), "utf8");

test("admin gamification initializer registers route and nav helper", () => {
  const calls = [];
  const initializers = {};
  const sandbox = {
    app: {
      initializers: {
        add(name, fn) {
          initializers[name] = fn;
        },
      },
      routes: {},
      translator: {
        trans(key) {
          return key;
        },
      },
      session: {
        user: {
          id: () => 1,
          attribute(key) {
            if (key === "flatRateOwnerDashboard") {
              return {
                schema_version: 1,
                sections: [{ id: "overview" }, { id: "gamification" }],
              };
            }
            return null;
          },
          slug: () => "admin",
        },
      },
      route: {
        user() {
          return "/u/admin";
        },
      },
      request() {
        return Promise.resolve({ ok: true, sources: {}, data: {} });
      },
    },
    flarum: {
      reg: {
        get(_ext, id) {
          if (id === "common/extend") {
            return {
              extend(object, method, callback) {
                calls.push({ kind: "extend", object, method, callback });
              },
            };
          }
          if (id === "forum/components/UserPage") {
            function UserPage() {}
            UserPage.prototype.navItems = function () {};
            return UserPage;
          }
          if (id === "common/components/LinkButton") {
            return function LinkButton() {};
          }
          if (id === "common/components/Page") {
            function Page() {}
            return Page;
          }
          return null;
        },
      },
    },
    m() {
      return null;
    },
    module: { exports: {} },
    setInterval() {
      return 1;
    },
    clearInterval() {},
    document: { visibilityState: "visible" },
  };

  runInContext(source, createContext(sandbox));
  assert.equal(typeof initializers["flatrate-wiki-admin-gamification"], "function");
  initializers["flatrate-wiki-admin-gamification"]();
  assert.ok(sandbox.app.routes.userFlatRateGamification);
  assert.equal(sandbox.app.routes.userFlatRateGamification.path, "/u/:username/gamification");
  assert.equal(calls.some((c) => c.method === "navItems"), true);
  assert.equal(sandbox.FlatRateAdminGamification.hasGamification(
    sandbox.FlatRateAdminGamification.dto(sandbox.app.session.user)
  ), true);
});

test("ordinary owner without gamification section is denied by helper", () => {
  const sandbox = {
    app: undefined,
    module: { exports: {} },
  };
  runInContext(source, createContext(sandbox));
  const user = {
    attribute(key) {
      if (key === "flatRateOwnerDashboard") {
        return {
          schema_version: 1,
          sections: [{ id: "overview" }, { id: "identity" }],
        };
      }
      return null;
    },
  };
  assert.equal(sandbox.FlatRateAdminGamification.hasGamification(
    sandbox.FlatRateAdminGamification.dto(user)
  ), false);
});

test("Test Lab frontend is functional and secret-free", () => {
  assert.match(source, /class AdminGamificationPage extends/);
  assert.match(source, /test_lab_start|Start test session/);
  assert.equal(source.includes("test_lab_deferred"), false);
  assert.match(source, /\/api\/flatrate-admin\/gamification\/test-lab\//);
  assert.match(source, /share\/status/);
  assert.match(source, /ATTRIBUTION_POLL_MS = 5000/);
  assert.match(source, /ATTRIBUTION_POLL_MAX_MS = 60000/);
  assert.match(source, /stopAttributionPolling/);
  assert.match(source, /test_lab_attribution|Attribution State/);
  assert.match(source, /test_lab_assertions|Assertions/);
  for (const needle of [
    "ADMIN_GAMIFY_BRIDGE_SECRET",
    "GROWTH_SHARE_E2E_UNLOCK_SECRET",
    "SUPABASE_SERVICE_ROLE",
    "service_role",
    "claim_token_hash",
  ]) {
    assert.equal(source.includes(needle), false, `must not contain ${needle}`);
  }
});
