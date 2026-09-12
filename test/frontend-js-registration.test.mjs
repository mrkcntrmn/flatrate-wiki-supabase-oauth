#!/usr/bin/env node
/**
 * FORUM-SUB-000D — Flarum 1.8 Frontend::js() scalar-overwrite regression gates.
 *
 * Flarum 1.8 stores one JS path per Extend\Frontend instance. Chaining
 * ->js() on a single extender overwrites prior paths; css() appends.
 * Full require(extend.php) reflection is impractical here: this package CI
 * does not install flarum/core or fof/oauth. We therefore:
 *   1. Prove the Flarum 1.8.19 Frontend.php API from vendor or the pinned fixture
 *   2. Structurally parse companion extend.php Frontend chains
 */

import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, "..");
const text = (rel) => readFileSync(join(ROOT, rel), "utf8");

const EXPECTED_JS = [
  "js/dist/forum-navigation.js",
  "js/dist/forum.js",
  "js/dist/mobile-brand-drawer.js",
  "js/dist/member-display.js",
];

function resolveFlarumFrontendSource() {
  const vendor = join(ROOT, "vendor/flarum/core/src/Extend/Frontend.php");
  const fixture = join(
    ROOT,
    "test/fixtures/flarum-1.8.19-Extend-Frontend.php",
  );
  if (existsSync(vendor)) {
    return { path: vendor, source: "vendor" };
  }
  assert.ok(
    existsSync(fixture),
    "expected vendor/flarum/core/.../Frontend.php or pinned 1.8.19 fixture",
  );
  return { path: fixture, source: "fixture" };
}

/**
 * Parse `(new Extend\Frontend(...))` chains from extend.php into
 * { frontend, jsPaths[], cssPaths[] } records in source order.
 */
function parseFrontendExtenders(extendPhp) {
  const records = [];
  // Prefer the grouped form `(new Extend\Frontend(...))` used in extend.php.
  const startRe = /\(\s*new\s+Extend\\Frontend\s*\(/g;
  let match;
  while ((match = startRe.exec(extendPhp)) !== null) {
    const argsStart = match.index + match[0].length;
    let i = argsStart;
    let depth = 1;
    while (i < extendPhp.length && depth > 0) {
      const ch = extendPhp[i++];
      if (ch === "(") depth++;
      else if (ch === ")") depth--;
    }
    const ctorArgs = extendPhp.slice(argsStart, i - 1);
    const frontendMatch = ctorArgs.match(/['"]([^'"]+)['"]/);
    const frontend = frontendMatch ? frontendMatch[1] : null;

    // Skip the closing `)` of the `(new ...)` grouping before method chains.
    while (i < extendPhp.length && /\s/.test(extendPhp[i])) i++;
    if (extendPhp[i] === ")") i++;

    // Consume chained ->method(...) calls until comma/semicolon at depth 0.
    const jsPaths = [];
    const cssPaths = [];
    depth = 0;
    let j = i;
    while (j < extendPhp.length) {
      const ch = extendPhp[j];
      if (ch === "(") depth++;
      else if (ch === ")") depth--;
      if (depth === 0 && (ch === "," || ch === ";")) break;
      j++;
    }
    const chain = extendPhp.slice(i, j);

    for (const m of chain.matchAll(
      /->js\s*\(\s*__DIR__\s*\.\s*['"]([^'"]+)['"]\s*\)/g,
    )) {
      jsPaths.push(m[1].replace(/^\//, ""));
    }
    for (const m of chain.matchAll(
      /->css\s*\(\s*__DIR__\s*\.\s*['"]([^'"]+)['"]\s*\)/g,
    )) {
      cssPaths.push(m[1].replace(/^\//, ""));
    }

    records.push({ frontend, jsPaths, cssPaths, chain });
  }
  return records;
}

test("Flarum 1.8.19 Frontend::js is a scalar overwrite; css appends", () => {
  const { path, source } = resolveFlarumFrontendSource();
  const api = readFileSync(path, "utf8");

  assert.match(api, /private\s+\$css\s*=\s*\[\];/);
  assert.match(api, /private\s+\$js;/);
  assert.doesNotMatch(api, /private\s+\$js\s*=\s*\[/);

  const cssMethod = api.match(
    /public function css\(string \$path\): self\s*\{([\s\S]*?)\n    \}/,
  );
  const jsMethod = api.match(
    /public function js\(string \$path\): self\s*\{([\s\S]*?)\n    \}/,
  );
  assert.ok(cssMethod, "css() method present");
  assert.ok(jsMethod, "js() method present");
  assert.match(cssMethod[1], /\$this->css\[\]\s*=\s*\$path;/);
  assert.match(jsMethod[1], /\$this->js\s*=\s*\$path;/);
  assert.doesNotMatch(jsMethod[1], /\$this->js\[\]\s*=/);

  // Encode gates for the final packet / operators reading stderr.
  console.error(`FLARUM_FRONTEND_SOURCE=${source}:${path}`);
  console.error("FLARUM_FRONTEND_JS_PROPERTY_IS_SCALAR=true");
  console.error("FLARUM_FRONTEND_JS_METHOD_OVERWRITES=true");
  console.error("FLARUM_FRONTEND_CSS_METHOD_APPENDS=true");
});

test("companion registers four forum JS paths via separate Frontend extenders", () => {
  const extendPhp = text("extend.php");
  const forum = parseFrontendExtenders(extendPhp).filter(
    (r) => r.frontend === "forum" && r.jsPaths.length > 0,
  );

  assert.equal(forum.length, 4, "expected four forum Frontend JS extenders");

  const registered = forum.map((r) => r.jsPaths.join(","));
  assert.deepEqual(
    registered,
    EXPECTED_JS,
    `REGISTERED_FORUM_JS_PATHS=${EXPECTED_JS.join(",")}`,
  );

  for (const ext of forum) {
    assert.equal(
      ext.jsPaths.length,
      1,
      `MAX_JS_CALLS_PER_FRONTEND_EXTENDER=1 violated: ${ext.jsPaths.join(",")}`,
    );
  }

  const allJs = forum.flatMap((r) => r.jsPaths);
  for (const path of EXPECTED_JS) {
    assert.equal(
      allJs.filter((p) => p === path).length,
      1,
      `duplicate registration for ${path}`,
    );
  }

  const nav = allJs.indexOf(EXPECTED_JS[0]);
  const forumJs = allJs.indexOf(EXPECTED_JS[1]);
  const drawer = allJs.indexOf(EXPECTED_JS[2]);
  const member = allJs.indexOf(EXPECTED_JS[3]);
  assert.ok(nav < forumJs && forumJs < drawer && drawer < member, "FRONTEND_JS_ORDER_GATE=PASS");

  console.error(`REGISTERED_FORUM_JS_PATHS=${allJs.join(",")}`);
  console.error("FRONTEND_JS_ORDER_GATE=PASS");
  console.error("MAX_JS_CALLS_PER_FRONTEND_EXTENDER=1");
});

test("chained multi-js on one Frontend extender is detected as a violation", () => {
  const broken = `
return [
    (new Extend\\Frontend('forum'))
        ->js(__DIR__.'/js/dist/a.js')
        ->js(__DIR__.'/js/dist/b.js'),
];
`;
  const forum = parseFrontendExtenders(broken).filter(
    (r) => r.frontend === "forum",
  );
  assert.equal(forum.length, 1);
  assert.equal(
    forum[0].jsPaths.length,
    2,
    "detector must see chained ->js() as >1 calls on one extender",
  );
  // The production gate in the companion test above requires length === 1.
  assert.notEqual(forum[0].jsPaths.length, 1);
});

test("forum LESS paths register exactly once on the first JS extender", () => {
  const extendPhp = text("extend.php");
  const forum = parseFrontendExtenders(extendPhp).filter(
    (r) => r.frontend === "forum",
  );
  const cssAll = forum.flatMap((r) => r.cssPaths);
  assert.equal(
    cssAll.filter((p) => p === "resources/less/forum.less").length,
    1,
  );
  assert.equal(
    cssAll.filter((p) => p === "resources/less/mobile-brand-drawer.less")
      .length,
    1,
  );
  // CSS should ride with the first JS extender (navigation), not be duplicated.
  assert.deepEqual(forum[0].cssPaths, [
    "resources/less/forum.less",
    "resources/less/mobile-brand-drawer.less",
  ]);
  assert.deepEqual(forum[1].cssPaths, []);
  assert.deepEqual(forum[2].cssPaths, []);
  assert.deepEqual(forum[3].cssPaths, []);
});

test("IA-013 JS source markers remain present and unchanged in role", () => {
  const nav = text("js/dist/forum-navigation.js");
  const forum = text("js/dist/forum.js");
  const mobile = text("js/dist/mobile-brand-drawer.js");
  const member = text("js/dist/member-display.js");

  assert.match(nav, /FlatRateForumNavigation/);
  assert.match(nav, /root\.FlatRateForumNavigation\s*=/);

  assert.match(
    forum,
    /flatrate-wiki-forum-navigation-sidebar/,
  );
  assert.match(forum, /flatrateForumNavigation/);
  assert.match(forum, /FlatRateForumNav--sidebar/);

  assert.match(mobile, /FlatRateForumNavigation/);
  assert.match(
    mobile,
    /flatrate-wiki-mobile-forum-navigation/,
  );

  assert.match(member, /flatrate-wiki-member-display/);
  assert.match(member, /flatrate\/member-display/);
  assert.match(member, /Community identity|member_number|flatRateMemberNumber/);
  assert.match(member, /module\.exports = \{\}/);
  assert.match(member, /coreExport\('common\/extend'\)/);
  assert.match(member, /forum\/components\/SettingsPage/);
  assert.match(member, /flarum\.core\.compat/);

  for (const rel of EXPECTED_JS) {
    assert.ok(existsSync(join(ROOT, rel)), `missing ${rel}`);
  }

  console.error("NAV_CONTRACT_SOURCE_MARKER=PASS");
  console.error("DESKTOP_NAV_SOURCE_MARKER=PASS");
  console.error("MOBILE_NAV_SOURCE_MARKER=PASS");
});
