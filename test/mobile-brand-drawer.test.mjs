import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { runInNewContext } from "node:vm";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");
const plain = (value) => JSON.parse(JSON.stringify(value));

class ItemList {
  constructor() {
    this.items = new Map();
  }

  add(name, item, priority = 0) {
    this.items.set(name, { item, priority });
  }

  has(name) {
    return this.items.has(name);
  }

  get(name) {
    return this.items.get(name)?.item;
  }

  getPriority(name) {
    return this.items.get(name)?.priority;
  }
}

function flarumExtend(object, method, callback) {
  const original = object[method];

  object[method] = function (...args) {
    const value = typeof original === "function" ? original.apply(this, args) : undefined;
    callback.apply(this, [value, ...args]);
    return value;
  };
}

function createMithril(activeSlug = "") {
  function mithril(selector, attrsOrChildren, children) {
    if (arguments.length === 2) {
      if (
        Array.isArray(attrsOrChildren) ||
        typeof attrsOrChildren === "string" ||
        attrsOrChildren == null ||
        (typeof attrsOrChildren === "object" && "selector" in attrsOrChildren)
      ) {
        return {
          selector,
          attrs: {},
          children: Array.isArray(attrsOrChildren)
            ? attrsOrChildren
            : attrsOrChildren == null
              ? []
              : [attrsOrChildren],
        };
      }
    }

    return {
      selector,
      attrs: attrsOrChildren || {},
      children: Array.isArray(children) ? children : children == null ? [] : [children],
    };
  }

  mithril.route = {
    param(name) {
      return name === "tags" ? activeSlug : undefined;
    },
  };

  return mithril;
}

function tag({ name, slug, position, child = false }) {
  return {
    name: () => name,
    slug: () => slug,
    position: () => position,
    isChild: () => child,
    parent: () => (child ? { id: () => "parent" } : null),
  };
}

async function sharedNavigationContract() {
  const context = { module: { exports: {} } };
  runInNewContext(await text("js/dist/forum-navigation.js"), context);
  return context.module.exports;
}

function tagsForContract(contract) {
  const tags = [];
  // Match live B2 topology: GM/CDJR constituents are primary roots, not Flarum children.
  for (const group of contract.groups) {
    for (const node of group.children) {
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
}

function groupSections(nav) {
  return nav.children;
}

function brandGroup(nav) {
  return groupSections(nav).find((section) => section.attrs["data-group"] === "brands");
}

function drawerTopLevelItems(group) {
  return group.children[1].children.filter((item) =>
    String(item.selector).startsWith("li.FlatRateForumNav-item"),
  );
}

function drawerLinkItems(group) {
  return group.children[1].children.flatMap((item) => {
    if (String(item.selector).startsWith("li.FlatRateForumNav-item")) return [item];
    return item.children[0].children;
  });
}

async function drawerRuntime({ activeSlug = "toyota", tags = [] } = {}) {
  const shared = await text("js/dist/forum-navigation.js");
  const bundle = await text("js/dist/mobile-brand-drawer.js");
  const initializers = new Map();

  class HeaderSecondary {
    items() {
      const items = new ItemList();
      items.add("search", { selector: "Search" }, 30);
      items.add("notifications", { selector: "Notifications" }, 10);
      items.add("Messages", { selector: "Messages" }, 5);
      items.add("session", { selector: "Session" }, 0);
      return items;
    }
  }

  class TagLinkButton {}

  const compat = {
    extend: { extend: flarumExtend },
    "components/HeaderSecondary": HeaderSecondary,
    "tags/components/TagLinkButton": TagLinkButton,
  };

  const app = {
    initializers: {
      add(name, initializer) {
        initializers.set(name, initializer);
      },
    },
    store: {
      all(type) {
        return type === "tags" ? tags : [];
      },
    },
  };

  runInNewContext(`${shared}\n${bundle}`, {
    app,
    flarum: { core: { compat } },
    m: createMithril(activeSlug),
    module: { exports: {} },
  });

  const initializer = initializers.get("flatrate-wiki-mobile-forum-navigation");
  assert.equal(typeof initializer, "function");
  initializer();

  return { HeaderSecondary, TagLinkButton };
}

test("mobile forum navigation hooks HeaderSecondary below core drawer controls", async () => {
  const bundle = await text("js/dist/mobile-brand-drawer.js");

  assert.match(bundle, /compat\['components\/HeaderSecondary'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/HeaderSecondary'\]/);
  assert.match(bundle, /compat\['tags\/components\/TagLinkButton'\]/);
  assert.match(bundle, /extend\(HeaderSecondary\.prototype, 'items'/);
  assert.doesNotMatch(bundle, /components\/HeaderPrimary|forum\/components\/HeaderPrimary/);
  assert.doesNotMatch(bundle, /IndexPage\.prototype/);
  assert.doesNotMatch(bundle, /sidebarItems/);
  assert.match(bundle, /FlatRateForumNavigation/);
  assert.doesNotMatch(bundle, /var GROUPS = /);
  assert.doesNotMatch(bundle, /FlatRateBrandsNavigation/);
});

test("mobile drawer renders Community, Technician Topics, and Brands from the shared contract", async () => {
  const contract = await sharedNavigationContract();
  const tags = tagsForContract(contract);
  assertProductionLikeTopology(tags);
  const { HeaderSecondary, TagLinkButton } = await drawerRuntime({
    activeSlug: "chevrolet",
    tags,
  });

  const items = new HeaderSecondary().items();
  assert.equal(items.has("flatrateForumNavigationDrawer"), true);
  assert.equal(items.getPriority("flatrateForumNavigationDrawer"), -50);
  assert.ok(items.getPriority("flatrateForumNavigationDrawer") < items.getPriority("session"));
  assert.ok(items.getPriority("flatrateForumNavigationDrawer") < items.getPriority("Messages"));
  assert.ok(items.getPriority("flatrateForumNavigationDrawer") < items.getPriority("notifications"));
  assert.ok(items.getPriority("flatrateForumNavigationDrawer") < items.getPriority("search"));

  const nav = items.get("flatrateForumNavigationDrawer");
  assert.equal(nav.selector, "nav.FlatRateForumNav.FlatRateForumNav--drawer");
  assert.equal(nav.attrs["aria-label"], "Forum navigation");
  assert.deepEqual(
    plain(groupSections(nav).map((section) => section.children[0].children[0])),
    ["Community", "Technician Topics", "Brands"],
  );
  assert.deepEqual(
    plain(groupSections(nav).map((section) => section.attrs["data-group"])),
    ["community", "technician-topics", "brands"],
  );

  const brands = brandGroup(nav);
  const community = groupSections(nav).find((section) => section.attrs["data-group"] === "community");
  const technicianTopics = groupSections(nav).find(
    (section) => section.attrs["data-group"] === "technician-topics",
  );
  assert.deepEqual(
    plain(drawerTopLevelItems(community).map((item) => item.children[0].children[0])),
    ["Start Here"],
  );
  assert.deepEqual(
    plain(drawerTopLevelItems(technicianTopics).map((item) => item.children[0].children[0])),
    ["General Shop Discussion"],
  );
  assert.deepEqual(
    plain(drawerTopLevelItems(brands).map((item) => item.children[0].children[0])),
    plain(contract.getGroup("brands").children.map((node) => node.displayName)),
  );
  assert.deepEqual(
    plain(
      brands.children[1].children
        .at(brands.children[1].children.findIndex((item) => item.children[0].children[0] === "CDJR") + 1)
        .children[0].children.map((item) => item.children[0].children[0]),
    ),
    ["Chrysler", "Dodge", "Jeep", "Ram"],
  );
  assert.deepEqual(
    plain(
      brands.children[1].children
        .at(brands.children[1].children.findIndex((item) => item.children[0].children[0] === "GM") + 1)
        .children[0].children.map((item) => item.children[0].children[0]),
    ),
    ["Buick", "Cadillac", "Chevrolet", "GMC"],
  );
  assert.ok(drawerLinkItems(brands).every((item) => item.children[0].selector === TagLinkButton));
  assert.deepEqual(
    plain(
      drawerLinkItems(brands)
        .filter((item) => item.selector.includes(".active"))
        .map((item) => item.children[0].children[0]),
    ),
    ["Chevrolet"],
  );
  assert.equal(drawerTopLevelItems(brands).some((item) => item.children[0].children[0] === "Buick"), false);
  assert.equal(drawerLinkItems(brands).length, 41);
  assert.ok(
    drawerLinkItems(brands)
      .filter((item) => ["Buick", "Cadillac", "Chevrolet", "GMC", "Chrysler", "Dodge", "Jeep", "Ram"].includes(item.children[0].children[0]))
      .every((item) => item.children[0].attrs.model.isChild() === false && item.children[0].attrs.model.parent() === null),
  );
});

test("mobile drawer selects Community boards by active slug", async () => {
  const contract = await sharedNavigationContract();
  const { HeaderSecondary } = await drawerRuntime({
    activeSlug: "start-here",
    tags: tagsForContract(contract),
  });

  const community = groupSections(new HeaderSecondary().items().get("flatrateForumNavigationDrawer")).find(
    (section) => section.attrs["data-group"] === "community",
  );

  assert.deepEqual(
    plain(
      drawerLinkItems(community)
        .filter((item) => item.selector.includes(".active"))
        .map((item) => item.children[0].children[0]),
    ),
    ["Start Here"],
  );
});

test("mobile drawer fails closed when tag data is unavailable", async () => {
  const { HeaderSecondary } = await drawerRuntime({ tags: [] });
  const items = new HeaderSecondary().items();

  assert.equal(items.has("flatrateForumNavigationDrawer"), false);
  assert.equal(items.has("search"), true);
});

test("mobile drawer keeps Brands when one nested child tag is missing", async () => {
  const contract = await sharedNavigationContract();
  const { HeaderSecondary } = await drawerRuntime({
    tags: tagsForContract(contract).filter((candidate) => candidate.slug() !== "cadillac"),
  });
  const items = new HeaderSecondary().items();

  assert.equal(items.has("flatrateForumNavigationDrawer"), true);
  const brands = brandGroup(items.get("flatrateForumNavigationDrawer"));
  assert.equal(drawerLinkItems(brands).length, 40);
});

test("mobile drawer CSS suppresses the legacy page-flow copy and is phone-only", async () => {
  const less = await text("resources/less/mobile-brand-drawer.less");

  assert.match(
    less,
    /\.IndexPage-nav > ul > \.item-flatrateForumNavigation\s*,\s*\.IndexPage-nav > ul > \.item-flatrateMobileBrandLinks\s*\{\s*display: none !important;/s,
  );
  assert.match(
    less,
    /\.FlatRateForumNav--drawer,\s*\.Header-secondary \.item-flatrateForumNavigationDrawer/,
  );
  assert.match(
    less,
    /@media \(max-width: 767px\)[\s\S]*\.Header-secondary \.item-flatrateForumNavigationDrawer\s*\{[\s\S]*display: block;/,
  );
  assert.match(
    less,
    /\.FlatRateForumNav--drawer \.FlatRateForumNav-links\s*\{[\s\S]*max-height: 60vh;[\s\S]*overflow-y: auto;/,
  );
  assert.match(less, /\.FlatRateForumNav-link\.TagLinkButton/);
});

test("frontend extender loads the drawer bundle and override stylesheet", async () => {
  const extendPhp = await text("extend.php");

  assert.match(extendPhp, /resources\/less\/mobile-brand-drawer\.less/);
  assert.match(extendPhp, /js\/dist\/forum-navigation\.js/);
  assert.match(extendPhp, /js\/dist\/mobile-brand-drawer\.js/);
  assert.match(extendPhp, /js\/dist\/forum\.js/);
});
