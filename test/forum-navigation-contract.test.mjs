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
  runInNewContext(await text("js/dist/forum-navigation.js"), context);
  return context.module.exports;
}

function flattenBoardKeys(nodes, acc = []) {
  for (const node of nodes) {
    acc.push(node.boardKey);
    if (node.children?.length) flattenBoardKeys(node.children, acc);
  }
  return acc;
}

function tagsForNavigation(contract, { technicianTopics = [] } = {}) {
  const community = contract.getGroup("community").children;
  const brands = contract.getGroup("brands").children;
  const tags = [];

  // Match live B2 topology: every primary board is a root, including GM/CDJR
  // presentation children. Nesting comes only from FlatRateForumNavigation.
  for (const node of [...community, ...technicianTopics, ...brands]) {
    tags.push(tag({ name: node.displayName, slug: node.slug, position: 1000 - tags.length, child: false }));
    for (const child of node.children ?? []) {
      tags.push(tag({
        name: child.displayName,
        slug: child.slug,
        position: 1000 - tags.length,
        child: false,
      }));
    }
  }

  tags.push(tag({ name: "Job Breakdown", slug: "job-breakdown", position: null, child: false }));
  return tags.reverse();
}

function assertProductionLikeTopology(tags) {
  const primaryRoots = tags.filter((candidate) => candidate.position() !== null);
  const primaryChildren = tags.filter((candidate) => candidate.isChild());
  const secondary = tags.filter((candidate) => candidate.position() === null);
  const presentationChildrenWithFlarumParent = tags.filter(
    (candidate) =>
      ["buick", "cadillac", "chevrolet", "gmc", "chrysler", "dodge", "jeep", "ram"].includes(candidate.slug()) &&
      (candidate.isChild() || candidate.parent() !== null),
  );

  assert.equal(primaryRoots.length, 43);
  assert.equal(primaryChildren.length, 0);
  assert.equal(secondary.length, 1);
  assert.equal(secondary[0].slug(), "job-breakdown");
  assert.equal(presentationChildrenWithFlarumParent.length, 0);
  assert.ok(tags.every((candidate) => candidate.parent() === null));
}

test("forum navigation has one shared production tree source", async () => {
  const files = {
    shared: await text("js/dist/forum-navigation.js"),
    forum: await text("js/dist/forum.js"),
    drawer: await text("js/dist/mobile-brand-drawer.js"),
  };

  const sourceCount = Object.values(files).filter((source) =>
    /var GROUPS = deepFreeze\(\[/.test(source),
  ).length;
  assert.equal(sourceCount, 1);
  assert.match(files.shared, /var GROUPS = deepFreeze\(\[/);
  assert.match(files.forum, /FlatRateForumNavigation/);
  assert.match(files.drawer, /FlatRateForumNavigation/);
  assert.doesNotMatch(files.forum, /var GROUPS = /);
  assert.doesNotMatch(files.drawer, /var GROUPS = /);
  assert.doesNotMatch(files.forum, /FlatRateBrandsNavigation/);
  assert.doesNotMatch(files.drawer, /FlatRateBrandsNavigation/);
  assert.doesNotMatch(files.forum, /const brands\s*=\s*\[/);
  assert.doesNotMatch(files.drawer, /const brands\s*=\s*\[/);
});

test("canonical group order and labels are fixed", async () => {
  const contract = await loadSharedContract();

  assert.deepEqual(
    plain(contract.groups.map((group) => group.id)),
    ["community", "technician-topics", "brands"],
  );
  assert.deepEqual(
    plain(contract.groups.map((group) => group.label)),
    ["Community", "Technician Topics", "Brands"],
  );
  assert.equal(Object.isFrozen(contract.groups), true);
});

test("Community boards are ordered and named exactly", async () => {
  const contract = await loadSharedContract();
  const community = contract.getGroup("community");

  assert.equal(community.children.length, 2);
  assert.deepEqual(
    plain(community.children.map((node) => node.slug)),
    ["start-here", "general-shop-discussion"],
  );
  assert.deepEqual(
    plain(community.children.map((node) => node.displayName)),
    ["Start Here", "General Shop Discussion"],
  );
});

test("Technician Topics stays empty and hide-until-nonempty", async () => {
  const contract = await loadSharedContract();
  const technicianTopics = contract.getGroup("technician-topics");

  assert.equal(technicianTopics.children.length, 0);
  assert.equal(technicianTopics.emptyPolicy, "hide-until-nonempty");
});

test("Technician Topics appears between Community and Brands when nonempty", async () => {
  const contract = await loadSharedContract();
  const groups = contract.groups.map((group) => contract.cloneGroup(group));
  groups[1].children = [
    {
      boardKey: "diagnostics",
      displayName: "Diagnostics",
      slug: "diagnostics",
      children: [],
    },
  ];

  const app = {
    store: {
      all(type) {
        assert.equal(type, "tags");
        return tagsForNavigation(contract, {
          technicianTopics: groups[1].children,
        });
      },
    },
  };

  const resolved = contract.resolve(app, groups);
  assert.deepEqual(
    plain(resolved.map((group) => group.label)),
    ["Community", "Technician Topics", "Brands"],
  );
});

test("Brands tree pins counts, order, GM/CDJR children, and Other Makes", async () => {
  const contract = await loadSharedContract();
  const brands = contract.getGroup("brands").children;
  const topLevel = brands.map((node) => node.displayName);
  const gm = brands.find((node) => node.boardKey === "gm");
  const cdjr = brands.find((node) => node.boardKey === "cdjr");
  const childCount = brands.reduce((count, node) => count + (node.children?.length ?? 0), 0);

  assert.equal(topLevel.length, 33);
  assert.equal(childCount, 8);
  assert.equal(topLevel.length + childCount, 41);
  assert.equal(topLevel.at(topLevel.indexOf("BMW") + 1), "CDJR");
  assert.equal(topLevel.at(topLevel.indexOf("Genesis") + 1), "GM");
  assert.ok(topLevel.indexOf("Nissan") < topLevel.indexOf("Other Makes"));
  assert.ok(topLevel.indexOf("Other Makes") < topLevel.indexOf("Porsche"));
  assert.deepEqual(
    plain(cdjr.children.map((child) => child.boardKey)),
    ["chrysler", "dodge", "jeep", "ram"],
  );
  assert.deepEqual(
    plain(gm.children.map((child) => child.boardKey)),
    ["buick", "cadillac", "chevrolet", "gmc"],
  );
  assert.deepEqual(
    plain(topLevel),
    [
      "Acura",
      "Alfa Romeo",
      "Audi",
      "Bentley",
      "BMW",
      "CDJR",
      "Ferrari",
      "Ford",
      "Genesis",
      "GM",
      "Honda",
      "Hyundai",
      "Infiniti",
      "Jaguar",
      "Kia",
      "Lamborghini",
      "Lexus",
      "Lincoln",
      "Maserati",
      "Mazda",
      "McLaren",
      "Mercedes-Benz",
      "MINI",
      "Mitsubishi",
      "Nissan",
      "Other Makes",
      "Porsche",
      "Rivian",
      "Subaru",
      "Tesla",
      "Toyota",
      "Volkswagen",
      "Volvo",
    ],
  );
});

test("sub-brands are not duplicated at the Brands top level", async () => {
  const contract = await loadSharedContract();
  const brands = contract.getGroup("brands").children;
  const topLevelKeys = brands.map((node) => node.boardKey);
  const allKeys = flattenBoardKeys(brands);
  const duplicates = allKeys.filter((key, index) => allKeys.indexOf(key) !== index);

  assert.deepEqual(duplicates, []);
  for (const key of ["buick", "cadillac", "chevrolet", "gmc", "chrysler", "dodge", "jeep", "ram"]) {
    assert.equal(topLevelKeys.includes(key), false);
  }
});

test("corrected display names keep legacy Alfa Romeo / Genesis / McLaren slugs", async () => {
  const contract = await loadSharedContract();
  const brands = contract.getGroup("brands").children;
  const byName = Object.fromEntries(brands.map((node) => [node.displayName, node]));

  assert.equal(byName["Alfa Romeo"].slug, "alpha-romeo");
  assert.equal(byName.Genesis.slug, "genisis");
  assert.equal(byName.McLaren.slug, "mclaren");
  assert.equal(byName.Ferrari.displayName, "Ferrari");
  assert.equal(byName.Lamborghini.displayName, "Lamborghini");
  assert.equal(byName.Ferrari.displayName.endsWith(" "), false);
  assert.equal(byName.Lamborghini.displayName.endsWith(" "), false);

  for (const node of brands) {
    assert.doesNotMatch(node.displayName, /Alpha Romeo|Genisis|Mclaren/);
  }
});

test("fixtures model flat 43-root production topology, not GM/CDJR parent tags", async () => {
  const contract = await loadSharedContract();
  const tags = tagsForNavigation(contract);
  assertProductionLikeTopology(tags);

  const resolved = contract.resolve({
    store: {
      all(type) {
        assert.equal(type, "tags");
        return tags;
      },
    },
  });
  const brands = resolved.find((group) => group.id === "brands").children;
  const gm = brands.find((node) => node.boardKey === "gm");
  const cdjr = brands.find((node) => node.boardKey === "cdjr");

  assert.equal(gm.tag.isChild(), false);
  assert.equal(gm.tag.parent(), null);
  assert.deepEqual(
    plain(gm.children.map((child) => [child.boardKey, child.tag.isChild(), child.tag.parent()])),
    [
      ["buick", false, null],
      ["cadillac", false, null],
      ["chevrolet", false, null],
      ["gmc", false, null],
    ],
  );
  assert.deepEqual(
    plain(cdjr.children.map((child) => [child.boardKey, child.tag.isChild(), child.tag.parent()])),
    [
      ["chrysler", false, null],
      ["dodge", false, null],
      ["jeep", false, null],
      ["ram", false, null],
    ],
  );
});

test("resolver omits unresolved nodes and hides empty Technician Topics", async () => {
  const contract = await loadSharedContract();
  const app = {
    store: {
      all(type) {
        assert.equal(type, "tags");
        return tagsForNavigation(contract).filter((candidate) => candidate.slug() !== "cadillac");
      },
    },
  };

  const resolved = contract.resolve(app);
  assert.deepEqual(
    plain(resolved.map((group) => group.id)),
    ["community", "brands"],
  );
  assert.equal(resolved.find((group) => group.id === "brands").children.length, 33);
  assert.deepEqual(
    plain(
      resolved
        .find((group) => group.id === "brands")
        .children.find((node) => node.boardKey === "gm")
        .children.map((child) => child.boardKey),
    ),
    ["buick", "chevrolet", "gmc"],
  );
});

test("resolver returns routes and active-ready tag models without mutating source", async () => {
  const contract = await loadSharedContract();
  const tags = tagsForNavigation(contract);
  assertProductionLikeTopology(tags);
  const app = {
    store: {
      all() {
        return tags;
      },
    },
  };

  const before = plain(contract.groups);
  const resolved = contract.resolve(app);
  const brands = resolved.find((group) => group.id === "brands").children;
  const alfa = brands.find((node) => node.displayName === "Alfa Romeo");
  const chevrolet = brands
    .find((node) => node.boardKey === "gm")
    .children.find((node) => node.boardKey === "chevrolet");

  assert.equal(alfa.route, "/t/alpha-romeo");
  assert.equal(chevrolet.route, "/t/chevrolet");
  assert.equal(typeof alfa.tag.slug, "function");
  assert.equal(alfa.tag.slug(), "alpha-romeo");
  assert.equal(chevrolet.tag.isChild(), false);
  assert.equal(chevrolet.tag.parent(), null);
  assert.deepEqual(plain(contract.groups), before);
  assert.equal(
    brands.reduce((count, node) => count + 1 + (node.children?.length ?? 0), 0),
    41,
  );
});

test("navigation sources do not mutate Flarum tags over HTTP", async () => {
  const files = [
    await text("js/dist/forum-navigation.js"),
    await text("js/dist/forum.js"),
    await text("js/dist/mobile-brand-drawer.js"),
  ].join("\n");

  assert.doesNotMatch(files, /\/api\/tags\/order/);
  assert.doesNotMatch(files, /PATCH\s*\/api\/tags/);
  assert.doesNotMatch(files, /DELETE\s*\/api\/tags/);
  assert.doesNotMatch(files, /method:\s*['"]PATCH['"]/);
  assert.doesNotMatch(files, /method:\s*['"]DELETE['"]/);
  assert.doesNotMatch(files, /app\.request\([\s\S]*tags/);
});

test("frontend extender loads navigation source before renderers", async () => {
  const extendPhp = await text("extend.php");
  const navigationIndex = extendPhp.indexOf("js/dist/forum-navigation.js");
  const forumIndex = extendPhp.indexOf("js/dist/forum.js");
  const drawerIndex = extendPhp.indexOf("js/dist/mobile-brand-drawer.js");

  assert.ok(navigationIndex > -1);
  assert.ok(navigationIndex < forumIndex);
  assert.ok(forumIndex < drawerIndex);
  assert.doesNotMatch(extendPhp, /brands-navigation\.js/);
});
