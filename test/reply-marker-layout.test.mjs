import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("marked reply TagLabel is ordered after native post meta", async () => {
  const bundle = await text("js/dist/forum.js");

  assert.match(
    bundle,
    /items\.add\('flatrateJobBreakdownTag', label, -5\)/,
  );
  assert.doesNotMatch(
    bundle,
    /items\.add\('flatrateJobBreakdownTag', label, 85\)/,
  );
});

test("marked reply TagLabel is right-aligned without changing native post header layout", async () => {
  const css = await text("resources/less/forum.less");

  assert.match(
    css,
    /\.Post-header\s*>\s*ul\s*>\s*\.item-flatrateJobBreakdownTag\s*\{[^}]*float:\s*right;/s,
  );
  assert.match(
    css,
    /\.item-flatrateJobBreakdownTag\s*\{[^}]*margin-right:\s*0;/s,
  );
  assert.doesNotMatch(
    css,
    /\.Post-header\s*>\s*ul\s*\{[^}]*display:\s*flex;/s,
  );
});
