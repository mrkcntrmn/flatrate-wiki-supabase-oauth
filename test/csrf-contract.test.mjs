import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const serviceProvider = await readFile(new URL("../src/ServiceProvider.php", import.meta.url), "utf8");
const extension = await readFile(new URL("../extend.php", import.meta.url), "utf8");

test("HMAC bridge POST routes bypass browser CSRF and only those routes are exempted", () => {
  assert.match(serviceProvider, /flarum\.http\.csrfExemptPaths/);
  assert.match(serviceProvider, /'flatrate-sso\.provision'/);
  assert.match(serviceProvider, /'flatrate-sso\.ticket'/);
  assert.doesNotMatch(serviceProvider, /auth\/flatrate\/session/);
  assert.match(extension, /post\('\/flatrate-sso\/provision', 'flatrate-sso\.provision'/);
  assert.match(extension, /post\('\/flatrate-sso\/ticket', 'flatrate-sso\.ticket'/);
});
