import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("forum loads the compiled direct-login bundle", async () => {
  const extension = await text("extend.php");
  const bundle = await text("js/dist/forum.js");

  assert.match(extension, /->js\(__DIR__\.'\/js\/dist\/forum\.js'\)/);
  assert.match(bundle, /app\.initializers\.add\('flatrate-wiki-direct-login'/);
  assert.match(bundle, /module\.exports = \{\}/);
});

test("login redirect logic runs only inside the Flarum initializer", async () => {
  const bundle = await text("js/dist/forum.js");
  const initializerIndex = bundle.indexOf("app.initializers.add");
  const observerIndex = bundle.indexOf("new MutationObserver");
  const modalQueryIndex = bundle.indexOf("document.querySelector('.LogInModal')");

  assert.ok(initializerIndex >= 0);
  assert.ok(observerIndex > initializerIndex);
  assert.ok(modalQueryIndex > initializerIndex);
});

test("signed-out login preserves the forum location through the Community gateway", async () => {
  const bundle = await text("js/dist/forum.js");

  assert.match(bundle, /https:\/\/flatrate\.wiki\/login/);
  assert.match(bundle, /window\.location\.pathname/);
  assert.match(bundle, /window\.location\.search/);
  assert.match(bundle, /window\.location\.hash/);
  assert.match(bundle, /'\/community\?returnTo=' \+ encodeURIComponent\(currentForumReturnTo\(\)\)/);
  assert.match(bundle, /destination\.searchParams\.set\('next', next\)/);
  assert.match(bundle, /window\.location\.assign\(loginDestination\(\)\)/);
});
