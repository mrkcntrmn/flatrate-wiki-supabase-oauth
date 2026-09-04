import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { runInNewContext } from "node:vm";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

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

async function sidebarRuntime({ activeSlug = "toyota", tags = [] } = {}) {
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

  runInNewContext(bundle, {
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
});

test("mobile brand sidebar renders only primary root tags in live position order", async () => {
  const { IndexPage, LinkButton } = await sidebarRuntime({
    activeSlug: "toyota",
    tags: [
      tag({ name: "Job Breakdown", slug: "job-breakdown", position: null }),
      tag({ name: "Start Here", slug: "start-here", position: 0 }),
      tag({ name: "General Shop Discussion", slug: "general-shop-discussion", position: 1 }),
      tag({ name: "Toyota", slug: "toyota", position: 20 }),
      tag({ name: "Audi", slug: "audi", position: 1 }),
      tag({ name: "Child Tag", slug: "child-tag", position: 2, child: true }),
      tag({ name: "Ford", slug: "ford", position: 10 }),
    ],
  });

  const items = new IndexPage().sidebarItems();
  assert.equal(items.has("flatrateMobileBrandLinks"), true);
  assert.equal(items.getPriority("flatrateMobileBrandLinks"), -20);

  const nav = items.get("flatrateMobileBrandLinks");
  assert.equal(nav.selector, "nav.FlatRateMobileBrandSidebar");
  assert.equal(nav.attrs["aria-label"], "Vehicle brands");
  assert.equal(nav.children[0].selector, "div.FlatRateMobileBrandSidebar-title");
  assert.equal(nav.children[0].children[0], "Vehicle Brands");

  const links = nav.children[1];
  assert.equal(links.selector, "div.FlatRateMobileBrandSidebar-links");
  assert.deepEqual(
    links.children.map((link) => link.children[0]),
    ["Audi", "Ford", "Toyota"],
  );
  assert.ok(links.children.every((link) => link.selector === LinkButton));
  assert.deepEqual(
    links.children.map((link) => link.attrs.href),
    ["/t/audi", "/t/ford", "/t/toyota"],
  );
  assert.deepEqual(
    links.children.map((link) => link.attrs.active),
    [false, false, true],
  );
});

test("mobile brand sidebar fails closed when tag data is unavailable", async () => {
  const { IndexPage } = await sidebarRuntime({ tags: [] });
  const items = new IndexPage().sidebarItems();

  assert.equal(items.has("flatrateMobileBrandLinks"), false);
  assert.equal(items.has("newDiscussion"), true);
  assert.equal(items.has("nav"), true);
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
