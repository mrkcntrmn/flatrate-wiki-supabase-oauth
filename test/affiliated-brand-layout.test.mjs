import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("affiliated brand aligns with nickname column on mobile, not avatar edge", async () => {
  const css = await text("resources/less/forum.less");

  assert.match(
    css,
    /@media\s*\(\s*max-width:\s*767\.98px\s*\)\s*\{[^}]*\.PostUser\s+\.FlatRateAffiliatedBrand[^}]*margin-left:\s*37px/s,
  );
  assert.doesNotMatch(
    css,
    /@media\s*\(\s*min-width:\s*768px\s*\)\s*\{[^}]*\.FlatRateAffiliatedBrand[^}]*margin-left/s,
  );
  assert.doesNotMatch(css, /\.Post-header\s*>\s*ul\s*\{[^}]*display:\s*flex;/s);
});

test("affiliated brand mobile indent is scoped to PostUser only", async () => {
  const css = await text("resources/less/forum.less");

  assert.match(css, /\.PostUser\s+\.FlatRateAffiliatedBrand/);
  assert.doesNotMatch(css, /\.Post-header\s+\.FlatRateAffiliatedBrand/);
});
