import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("affiliation layout uses identity stack beneath nickname, not PostUser-name grid", async () => {
  const css = await text("resources/less/forum.less");

  assert.match(css, /\.FlatRatePostUserIdentityStack[\s\S]*display:\s*inline-grid/s);
  assert.match(css, /\.FlatRateAffiliatedBrand[\s\S]*grid-row:\s*2/s);
  assert.doesNotMatch(css, /\.PostUser-name:has\(\.FlatRateAffiliatedBrand\)[\s\S]*display:\s*inline-grid/s);
  assert.doesNotMatch(css, /display:\s*contents/s);
  assert.match(
    css,
    /\.Post-header > ul:has\(\.FlatRateAffiliatedBrand\) > \.item-user[\s\S]*vertical-align:\s*top/s,
  );
  assert.match(
    css,
    /\.Post-header > ul:has\(\.FlatRateAffiliatedBrand\) > \.item-meta[\s\S]*vertical-align:\s*top/s,
  );
  assert.doesNotMatch(css, /\.FlatRateAffiliatedBrand[\s\S]*margin-left:\s*\d+px/s);
});

test("affiliation layout avoids global header and PostUser flex rewrites", async () => {
  const css = await text("resources/less/forum.less");

  assert.doesNotMatch(css, /\.Post-header\s*>\s*ul\s*\{[^}]*display:\s*flex;/s);
  assert.doesNotMatch(css, /^\.PostUser\s*\{[^}]*display:\s*flex;/ms);
  assert.doesNotMatch(css, /\.PostMeta\s*\{/s);
  assert.doesNotMatch(css, /\.PostMeta-time\s*\{/s);
});

test("affiliation layout keeps Job Breakdown float contract untouched", async () => {
  const css = await text("resources/less/forum.less");

  assert.match(
    css,
    /\.Post-header\s*>\s*ul\s*>\s*\.item-flatrateJobBreakdownTag\s*\{[^}]*float:\s*right;/s,
  );
  assert.doesNotMatch(css, /\.item-flatrateJobBreakdownTag[\s\S]*display:\s*flex/s);
});
