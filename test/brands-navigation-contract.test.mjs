import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { runInNewContext } from "node:vm";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");
const plain = (value) => JSON.parse(JSON.stringify(value));

function tag({ name, slug, position = null, child = false }) {
  return {
    name: () => name,
    slug: () => slug,
    position: () => position,
    isChild: () => child,
    parent: () => (child ? { id: () => "parent" } : null),
  };
}

async function loadSharedContract() {
  const context = { module: { exports: {} } };
  runInNewContext(await text("js/dist/brands-navigation.js"), context);
  return context.module.exports;
}

function tagsFromTree(tree) {
  return tree.flatMap((node, index) => [
    tag({ name: node.name, slug: node.slug, position: 999 - index, child: node.slug === "gm" }),
    ...(node.children ?? []).map((child) =>
      tag({ name: child.name, slug: child.slug, position: null, child: true }),
    ),
  ]).reverse();
}

test("Brands navigation has one shared production tree source", async () => {
  const files = {
    shared: await text("js/dist/brands-navigation.js"),
    forum: await text("js/dist/forum.js"),
    drawer: await text("js/dist/mobile-brand-drawer.js"),
  };

  const sourceCount = Object.values(files).filter((source) => /var BRAND_NAVIGATION_TREE = deepFreeze\(\[/.test(source)).length;
  assert.equal(sourceCount, 1);
  assert.match(files.shared, /var BRAND_NAVIGATION_TREE = deepFreeze\(\[/);
  assert.match(files.forum, /FlatRateBrandsNavigation/);
  assert.match(files.drawer, /FlatRateBrandsNavigation/);
  assert.doesNotMatch(files.forum, /var BRAND_NAVIGATION_TREE = /);
  assert.doesNotMatch(files.drawer, /var BRAND_NAVIGATION_TREE = /);
});

test("shared Brands tree pins top-level order and GM/CDJR children", async () => {
  const contract = await loadSharedContract();
  const topLevel = contract.tree.map((node) => node.name);
  const gm = contract.tree.find((node) => node.name === "GM");
  const cdjr = contract.tree.find((node) => node.name === "CDJR");

  assert.equal(Object.isFrozen(contract.tree), true);
  assert.equal(topLevel.length, 33);
  assert.equal(topLevel.at(topLevel.indexOf("BMW") + 1), "CDJR");
  assert.equal(topLevel.at(topLevel.indexOf("Genesis") + 1), "GM");
  assert.equal(topLevel.at(topLevel.indexOf("Nissan") + 1), "Other Makes");
  assert.deepEqual(plain(cdjr.children.map((child) => child.name)), ["Chrysler", "Dodge", "Jeep", "Ram"]);
  assert.deepEqual(plain(gm.children.map((child) => child.name)), ["Buick", "Cadillac", "Chevrolet", "GMC"]);
});

test("shared resolver ignores root status and live position", async () => {
  const contract = await loadSharedContract();
  const app = {
    store: {
      all(type) {
        assert.equal(type, "tags");
        return tagsFromTree(contract.tree);
      },
    },
  };

  const resolved = contract.resolve(app);
  assert.equal(resolved.length, 33);
  assert.equal(resolved.reduce((count, node) => count + 1 + (node.children?.length ?? 0), 0), 41);
  assert.equal(resolved.some((node) => ["Buick", "Cadillac", "Chevrolet", "GMC"].includes(node.name)), false);
  assert.deepEqual(
    plain(resolved.find((node) => node.name === "GM").children.map((child) => child.name)),
    ["Buick", "Cadillac", "Chevrolet", "GMC"],
  );
});
