#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const source = readFileSync(join(ROOT, "js/dist/member-dashboard.js"), "utf8");

function UserPage() {}
UserPage.prototype.navItems = function () {};
function PostsUserPage() {}
PostsUserPage.prototype.content = function () {};
function UserCard() {}
UserCard.prototype.infoItems = function () {};
function LinkButton() {}

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
        trans(key, params) {
          return key + JSON.stringify(params || {});
        },
      },
      route: {
        user() {
          return "/u/tech_a1b2c3d4";
        },
      },
    },
    flarum: {
      extensions: {},
      reg: {
        get(ext, id) {
          if (ext === "core" && id === "common/extend") {
            return {
              extend(object, method, callback) {
                calls.push({ kind: "extend", object, method, callback });
              },
              override(object, method, callback) {
                calls.push({ kind: "override", object, method, callback });
              },
            };
          }
          if (ext === "core" && id === "forum/components/UserPage") {
            return UserPage;
          }
          if (ext === "core" && id === "forum/components/PostsUserPage") {
            return PostsUserPage;
          }
          if (ext === "core" && id === "forum/components/UserCard") {
            return UserCard;
          }
          if (ext === "core" && id === "common/components/LinkButton") {
            return LinkButton;
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

test("helper-only VM exposes DTO guards without requiring Flarum app", () => {
  const sandbox = { globalThis: {} };
  sandbox.globalThis = sandbox;
  runInContext(source, createContext(sandbox));
  const api = sandbox.FlatRateOwnerDashboard;
  assert.equal(typeof api.dto, "function");
  assert.equal(api.dto(null), null);
  assert.equal(
    api.dto({
      attribute() {
        return { schema_version: 1, sections: [{ id: "overview" }] };
      },
    }).schema_version,
    1,
  );
  assert.equal(
    api.dto({
      attribute() {
        return { schema_version: 2, sections: [] };
      },
    }),
    null,
  );
  assert.equal(
    api.memberNumber({
      attribute() {
        return 322;
      },
    }),
    322,
  );
  assert.equal(
    api.hasSection({ sections: [{ id: "overview" }] }, "overview"),
    true,
  );
  assert.equal(
    api.hasSection({ sections: [{ id: "overview" }] }, "merit"),
    false,
  );
});

test("initializer extends UserCard, UserPage, and PostsUserPage via registry", () => {
  const { sandbox, initializers, calls } = loadWithReg();
  assert.equal(typeof initializers["flatrate-wiki-member-dashboard"], "function");
  initializers["flatrate-wiki-member-dashboard"]();
  assert.deepEqual(
    calls.map((c) => c.kind + ":" + c.method),
    ["extend:infoItems", "extend:navItems", "override:content"],
  );
  assert.equal(calls[0].object, UserCard.prototype);
  assert.equal(calls[1].object, UserPage.prototype);
  assert.equal(calls[2].object, PostsUserPage.prototype);
  assert.equal(sandbox.module.exports && typeof sandbox.module.exports, "object");
});

test("owner chrome is withheld without server DTO even if a user object exists", () => {
  const { initializers, calls } = loadWithReg();
  initializers["flatrate-wiki-member-dashboard"]();
  const nav = calls.find((c) => c.method === "navItems");
  const items = {
    added: [],
    add(name) {
      this.added.push(name);
    },
  };
  nav.callback.call({ user: { attribute() { return null; } } }, items);
  assert.deepEqual(items.added, []);
});

test("source assigns module.exports and uses guarded coreExport", () => {
  assert.match(source, /coreExport\('common\/extend'\)/);
  assert.match(source, /coreExport\('forum\/components\/UserPage'\)/);
  assert.match(source, /extendModule\.override/);
  assert.match(source, /override\(PostsUserPage\.prototype, 'content'/);
  assert.match(source, /module\.exports = \{\}/);
  assert.doesNotMatch(source, /flarum\.reg\.get\([^)]+\)\.extend/);
});
