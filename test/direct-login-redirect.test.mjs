import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);

async function text(path) {
  return readFile(new URL(path, packageDir), "utf8");
}

test("forum login modal redirects directly to the FlatRate login page", async () => {
  const extension = await text("extend.php");
  const forumJs = await text("resources/js/forum.js");

  assert.match(extension, /->js\(__DIR__\.'\/resources\/js\/forum\.js'\)/);
  assert.match(forumJs, /https:\/\/flatrate\.wiki\/login/);
  assert.match(forumJs, /document\.querySelector\('\.LogInModal'\)/);
  assert.match(forumJs, /new MutationObserver/);
  assert.match(forumJs, /window\.location\.assign\(loginDestination\(\)\)/);
});

test("direct login keeps the current forum location through the Community gateway", async () => {
  const forumJs = await text("resources/js/forum.js");

  assert.match(forumJs, /window\.location\.pathname/);
  assert.match(forumJs, /window\.location\.search/);
  assert.match(forumJs, /window\.location\.hash/);
  assert.match(forumJs, /`\/community\?returnTo=\$\{encodeURIComponent\(currentForumReturnTo\(\)\)\}`/);
  assert.match(forumJs, /destination\.searchParams\.set\('next', next\)/);
  assert.match(forumJs, /!value\.startsWith\('\/\/'\)/);
});
