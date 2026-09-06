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
  runInNewContext(await text("js/dist/brands-navigation.js"), context);
  return context.module.exports;
}

function brandTagsFromTree(tree) {
  const flattened = [];
  for (const node of tree) {
    flattened.push(tag({ name: node.name, slug: node.slug, position: 1000 - flattened.length, child: node.slug === "cdjr" }));
    for (const child of node.children ?? []) {
      flattened.push(tag({ name: child.name, slug: child.slug, position: null, child: true }));
    }
  }
  return flattened.reverse();
}

function drawerTopLevelItems(nav) {
  return nav.children[1].children.filter((item) => item.selector === "li.FlatRateMobileBrandDrawer-item");
}

function drawerLinkItems(nav) {
  return nav.children[1].children.flatMap((item) => {
    if (item.selector === "li.FlatRateMobileBrandDrawer-item") return [item];
    return item.children[0].children;
  });
}

async function drawerRuntime({ activeSlug = "toyota", tags = [] } = {}) {
  const shared = await text("js/dist/brands-navigation.js");
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

  const initializer = initializers.get("flatrate-wiki-mobile-brand-drawer");
  assert.equal(typeof initializer, "function");
  initializer();

  return { HeaderSecondary, TagLinkButton };
}

test("mobile brand navigation hooks HeaderSecondary below core drawer controls", async () => {
  const bundle = await text("js/dist/mobile-brand-drawer.js");

  assert.match(bundle, /compat\['components\/HeaderSecondary'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/HeaderSecondary'\]/);
  assert.match(bundle, /compat\['tags\/components\/TagLinkButton'\]/);
  assert.match(bundle, /extend\(HeaderSecondary\.prototype, 'items'/);
  assert.doesNotMatch(bundle, /components\/HeaderPrimary|forum\/components\/HeaderPrimary/);
  assert.doesNotMatch(bundle, /IndexPage\.prototype/);
  assert.doesNotMatch(bundle, /sidebarItems/);
  assert.match(bundle, /FlatRateBrandsNavigation/);
  assert.doesNotMatch(bundle, /BRAND_NAVIGATION_TREE/);
});

test("mobile brand drawer renders explicit Brands tree below Search/Notifications/DM/profile", async () => {
  const contract = await sharedNavigationContract();
  const { HeaderSecondary, TagLinkButton } = await drawerRuntime({
    activeSlug: "chevrolet",
    tags: [
      tag({ name: "Job Breakdown", slug: "job-breakdown", position: null }),
      tag({ name: "Start Here", slug: "start-here", position: 0 }),
      tag({ name: "General Shop Discussion", slug: "general-shop-discussion", position: 1 }),
      ...brandTagsFromTree(contract.tree),
    ],
  });

  const items = new HeaderSecondary().items();
  assert.equal(items.has("flatrateMobileBrandDrawer"), true);
  assert.equal(items.getPriority("flatrateMobileBrandDrawer"), -50);
  assert.ok(items.getPriority("flatrateMobileBrandDrawer") < items.getPriority("session"));
  assert.ok(items.getPriority("flatrateMobileBrandDrawer") < items.getPriority("Messages"));
  assert.ok(items.getPriority("flatrateMobileBrandDrawer") < items.getPriority("notifications"));
  assert.ok(items.getPriority("flatrateMobileBrandDrawer") < items.getPriority("search"));

  const nav = items.get("flatrateMobileBrandDrawer");
  assert.equal(nav.selector, "nav.FlatRateMobileBrandDrawer");
  assert.equal(nav.attrs["aria-label"], "Brands");
  assert.equal(nav.children[0].children[0], "Brands");

  const list = nav.children[1];
  assert.equal(list.selector, "ul.FlatRateMobileBrandDrawer-links");
  assert.deepEqual(
    plain(drawerTopLevelItems(nav).map((item) => item.children[0].children[0])),
    plain(contract.tree.map((node) => node.name)),
  );
  assert.deepEqual(
    plain(list.children
      .at(list.children.findIndex((item) => item.children[0].children[0] === "CDJR") + 1)
      .children[0].children.map((item) => item.children[0].children[0])),
    ["Chrysler", "Dodge", "Jeep", "Ram"],
  );
  assert.deepEqual(
    plain(list.children
      .at(list.children.findIndex((item) => item.children[0].children[0] === "GM") + 1)
      .children[0].children.map((item) => item.children[0].children[0])),
    ["Buick", "Cadillac", "Chevrolet", "GMC"],
  );
  assert.ok(drawerLinkItems(nav).every((item) => item.children[0].selector === TagLinkButton));
  assert.deepEqual(
    plain(drawerLinkItems(nav).map((item) => item.children[0].attrs.model.slug()).filter((slug) => slug === "chevrolet")),
    ["chevrolet"],
  );
  assert.deepEqual(
    plain(drawerLinkItems(nav).filter((item) => item.selector.includes(".active")).map((item) => item.children[0].children[0])),
    ["Chevrolet"],
  );
  assert.equal(drawerTopLevelItems(nav).some((item) => item.children[0].children[0] === "Buick"), false);
  assert.equal(drawerLinkItems(nav).length, 41);
});

test("mobile brand drawer fails closed when tag data is unavailable", async () => {
  const { HeaderSecondary } = await drawerRuntime({ tags: [] });
  const items = new HeaderSecondary().items();

  assert.equal(items.has("flatrateMobileBrandDrawer"), false);
  assert.equal(items.has("search"), true);
});

test("mobile brand drawer fails closed when explicit tree cannot resolve every tag", async () => {
  const contract = await sharedNavigationContract();
  const { HeaderSecondary } = await drawerRuntime({
    tags: brandTagsFromTree(contract.tree).filter((candidate) => candidate.slug() !== "cadillac"),
  });
  const items = new HeaderSecondary().items();

  assert.equal(items.has("flatrateMobileBrandDrawer"), false);
});

test("mobile drawer CSS suppresses the legacy page-flow copy and is phone-only", async () => {
  const less = await text("resources/less/mobile-brand-drawer.less");

  assert.match(
    less,
    /\.IndexPage-nav > ul > \.item-flatrateMobileBrandLinks\s*\{\s*display: none !important;/s,
  );
  assert.match(
    less,
    /\.FlatRateMobileBrandDrawer,\s*\.Header-secondary \.item-flatrateMobileBrandDrawer\s*\{\s*display: none;/s,
  );
  assert.match(
    less,
    /@media \(max-width: 767px\)[\s\S]*\.Header-secondary \.item-flatrateMobileBrandDrawer\s*\{[\s\S]*display: block;/,
  );
  assert.match(
    less,
    /\.FlatRateMobileBrandDrawer-links\s*\{[\s\S]*max-height: 60vh;[\s\S]*overflow-y: auto;/,
  );
  assert.match(less, /\.FlatRateMobileBrandDrawer-link\.TagLinkButton/);
});

test("frontend extender loads the drawer bundle and override stylesheet", async () => {
  const extendPhp = await text("extend.php");

  assert.match(extendPhp, /resources\/less\/mobile-brand-drawer\.less/);
  assert.match(extendPhp, /js\/dist\/brands-navigation\.js/);
  assert.match(extendPhp, /js\/dist\/mobile-brand-drawer\.js/);
  assert.match(extendPhp, /js\/dist\/forum\.js/);
});
