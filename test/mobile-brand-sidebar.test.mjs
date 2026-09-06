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
    if (arguments.length === 2 && Array.isArray(attrsOrChildren)) {
      return { selector, attrs: {}, children: attrsOrChildren };
    }

    if (arguments.length === 2 && typeof attrsOrChildren === "string") {
      return { selector, attrs: {}, children: [attrsOrChildren] };
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
    flattened.push(tag({ name: node.name, slug: node.slug, position: 1000 - flattened.length, child: node.slug === "gm" }));
    for (const child of node.children ?? []) {
      flattened.push(tag({ name: child.name, slug: child.slug, position: null, child: true }));
    }
  }
  return flattened.reverse();
}

function sidebarLinkNodes(nav) {
  const nodes = nav.children[1].children;
  return nodes.flatMap((node) => {
    const parent = node.children[0];
    const children = node.children[1]?.children ?? [];
    return [parent, ...children];
  });
}

async function sidebarRuntime({ activeSlug = "toyota", tags = [] } = {}) {
  const shared = await text("js/dist/brands-navigation.js");
  const bundle = await text("js/dist/forum.js");
  const initializers = new Map();

  class IndexPage {
    sidebarItems() {
      const items = new ItemList();
      items.add("newDiscussion", { selector: "button" }, 100);
      items.add("nav", { selector: "div" }, 90);
      return items;
    }
  }

  class LinkButton {}

  const compat = {
    extend: { extend: flarumExtend },
    "components/IndexPage": IndexPage,
    "components/LinkButton": LinkButton,
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
    route(name, params) {
      assert.equal(name, "tag");
      return `/t/${params.tags}`;
    },
  };

  runInNewContext(`${shared}\n${bundle}`, {
    app,
    flarum: { core: { compat } },
    m: createMithril(activeSlug),
    module: { exports: {} },
  });

  const initializer = initializers.get("flatrate-wiki-mobile-brand-sidebar");
  assert.equal(typeof initializer, "function");
  initializer();

  return { IndexPage, LinkButton };
}

test("mobile brand sidebar hooks IndexPage through Flarum compat", async () => {
  const bundle = await text("js/dist/forum.js");

  assert.match(bundle, /app\.initializers\.add\('flatrate-wiki-mobile-brand-sidebar'/);
  assert.match(bundle, /compat\['components\/IndexPage'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/IndexPage'\]/);
  assert.match(bundle, /extend\(IndexPage\.prototype, 'sidebarItems'/);
  assert.match(bundle, /FlatRateBrandsNavigation/);
  assert.doesNotMatch(bundle, /BRAND_NAVIGATION_TREE/);
});

test("mobile brand sidebar renders explicit Brands tree independent of live root heuristics", async () => {
  const contract = await sharedNavigationContract();
  const { IndexPage, LinkButton } = await sidebarRuntime({
    activeSlug: "chevrolet",
    tags: [
      tag({ name: "Job Breakdown", slug: "job-breakdown", position: null }),
      tag({ name: "Start Here", slug: "start-here", position: 0 }),
      tag({ name: "General Shop Discussion", slug: "general-shop-discussion", position: 1 }),
      ...brandTagsFromTree(contract.tree),
    ],
  });

  const items = new IndexPage().sidebarItems();
  assert.equal(items.has("flatrateMobileBrandLinks"), true);
  assert.equal(items.getPriority("flatrateMobileBrandLinks"), -20);

  const nav = items.get("flatrateMobileBrandLinks");
  assert.equal(nav.selector, "nav.FlatRateMobileBrandSidebar");
  assert.equal(nav.attrs["aria-label"], "Brands");
  assert.equal(nav.children[0].selector, "div.FlatRateMobileBrandSidebar-title");
  assert.equal(nav.children[0].children[0], "Brands");

  const links = nav.children[1];
  assert.equal(links.selector, "div.FlatRateMobileBrandSidebar-links");
  assert.deepEqual(
    plain(links.children.map((node) => node.children[0].children[0])),
    plain(contract.tree.map((node) => node.name)),
  );
  assert.deepEqual(
    plain(links.children.find((node) => node.children[0].children[0] === "CDJR").children[1].children.map((link) => link.children[0])),
    ["Chrysler", "Dodge", "Jeep", "Ram"],
  );
  assert.deepEqual(
    plain(links.children.find((node) => node.children[0].children[0] === "GM").children[1].children.map((link) => link.children[0])),
    ["Buick", "Cadillac", "Chevrolet", "GMC"],
  );
  assert.ok(sidebarLinkNodes(nav).every((link) => link.selector === LinkButton));
  assert.deepEqual(
    plain(sidebarLinkNodes(nav).map((link) => link.attrs.href).filter((href) => href === "/t/chevrolet")),
    ["/t/chevrolet"],
  );
  assert.deepEqual(
    plain(sidebarLinkNodes(nav).filter((link) => link.attrs.active).map((link) => link.children[0])),
    ["Chevrolet"],
  );
  assert.equal(links.children.some((node) => node.children[0].children[0] === "Buick"), false);
  assert.equal(sidebarLinkNodes(nav).length, 41);
});

test("mobile brand sidebar fails closed when tag data is unavailable", async () => {
  const { IndexPage } = await sidebarRuntime({ tags: [] });
  const items = new IndexPage().sidebarItems();

  assert.equal(items.has("flatrateMobileBrandLinks"), false);
  assert.equal(items.has("newDiscussion"), true);
  assert.equal(items.has("nav"), true);
});

test("mobile brand sidebar fails closed when explicit tree cannot resolve every tag", async () => {
  const contract = await sharedNavigationContract();
  const { IndexPage } = await sidebarRuntime({
    tags: brandTagsFromTree(contract.tree).filter((candidate) => candidate.slug() !== "ram"),
  });
  const items = new IndexPage().sidebarItems();

  assert.equal(items.has("flatrateMobileBrandLinks"), false);
});

test("mobile brand sidebar styling is phone-only and uses a single-column navigation list", async () => {
  const less = await text("resources/less/forum.less");

  assert.match(
    less,
    /\.FlatRateMobileBrandSidebar,\s*\.IndexPage-nav > ul > \.item-flatrateMobileBrandLinks\s*\{\s*display: none;/s,
  );
  assert.match(less, /@media \(max-width: 767px\)[\s\S]*\.item-flatrateMobileBrandLinks[\s\S]*display: block;/);
  assert.match(less, /\.FlatRateMobileBrandSidebar-links\s*\{[\s\S]*display: block;[\s\S]*width: 100%;/);
  assert.doesNotMatch(less, /\.FlatRateMobileBrandSidebar-links\s*\{[^}]*grid-template-columns:/s);
  assert.match(less, /\.FlatRateMobileBrandSidebar-link\.Button\s*\{[\s\S]*width: 100%;[\s\S]*background: transparent;/);
  assert.match(less, /\.FlatRateMobileBrandSidebar-link\.Button\.active\s*\{[\s\S]*@primary-color/);
});
