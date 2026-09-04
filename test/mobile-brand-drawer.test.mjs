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

async function drawerRuntime({ activeSlug = "toyota", tags = [] } = {}) {
  const bundle = await text("js/dist/mobile-brand-drawer.js");
  const initializers = new Map();

  class HeaderPrimary {
    items() {
      const items = new ItemList();
      items.add("existingHeaderLink", { selector: "a" }, 100);
      return items;
    }
  }

  class TagLinkButton {}

  const compat = {
    extend: { extend: flarumExtend },
    "components/HeaderPrimary": HeaderPrimary,
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

  runInNewContext(bundle, {
    app,
    flarum: { core: { compat } },
    m: createMithril(activeSlug),
    module: { exports: {} },
  });

  const initializer = initializers.get("flatrate-wiki-mobile-brand-drawer");
  assert.equal(typeof initializer, "function");
  initializer();

  return { HeaderPrimary, TagLinkButton };
}

test("mobile brand navigation hooks the real Flarum header drawer surface", async () => {
  const bundle = await text("js/dist/mobile-brand-drawer.js");

  assert.match(bundle, /compat\['components\/HeaderPrimary'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/HeaderPrimary'\]/);
  assert.match(bundle, /compat\['tags\/components\/TagLinkButton'\]/);
  assert.match(bundle, /extend\(HeaderPrimary\.prototype, 'items'/);
  assert.doesNotMatch(bundle, /IndexPage\.prototype/);
  assert.doesNotMatch(bundle, /sidebarItems/);
});

test("mobile brand drawer renders only primary root tags in live position order", async () => {
  const { HeaderPrimary, TagLinkButton } = await drawerRuntime({
    activeSlug: "toyota",
    tags: [
      tag({ name: "Job Breakdown", slug: "job-breakdown", position: null }),
      tag({ name: "Toyota", slug: "toyota", position: 20 }),
      tag({ name: "Audi", slug: "audi", position: 1 }),
      tag({ name: "Child Tag", slug: "child-tag", position: 2, child: true }),
      tag({ name: "Ford", slug: "ford", position: 10 }),
    ],
  });

  const items = new HeaderPrimary().items();
  assert.equal(items.has("flatrateMobileBrandDrawer"), true);
  assert.equal(items.getPriority("flatrateMobileBrandDrawer"), -50);
  assert.equal(items.has("existingHeaderLink"), true);

  const nav = items.get("flatrateMobileBrandDrawer");
  assert.equal(nav.selector, "nav.FlatRateMobileBrandDrawer");
  assert.equal(nav.attrs["aria-label"], "Vehicle brands");
  assert.equal(nav.children[0].children[0], "Vehicle Brands");

  const list = nav.children[1];
  assert.equal(list.selector, "ul.FlatRateMobileBrandDrawer-links");
  assert.deepEqual(
    list.children.map((item) => item.children[0].children[0]),
    ["Audi", "Ford", "Toyota"],
  );
  assert.ok(list.children.every((item) => item.children[0].selector === TagLinkButton));
  assert.deepEqual(
    list.children.map((item) => item.children[0].attrs.model.slug()),
    ["audi", "ford", "toyota"],
  );
  assert.deepEqual(
    list.children.map((item) => item.selector.includes(".active")),
    [false, false, true],
  );
});

test("mobile brand drawer fails closed when tag data is unavailable", async () => {
  const { HeaderPrimary } = await drawerRuntime({ tags: [] });
  const items = new HeaderPrimary().items();

  assert.equal(items.has("flatrateMobileBrandDrawer"), false);
  assert.equal(items.has("existingHeaderLink"), true);
});

test("mobile drawer CSS suppresses the legacy page-flow copy and is phone-only", async () => {
  const less = await text("resources/less/mobile-brand-drawer.less");

  assert.match(
    less,
    /\.IndexPage-nav > ul > \.item-flatrateMobileBrandLinks\s*\{\s*display: none !important;/s,
  );
  assert.match(
    less,
    /\.FlatRateMobileBrandDrawer,\s*\.Header-primary \.item-flatrateMobileBrandDrawer\s*\{\s*display: none;/s,
  );
  assert.match(
    less,
    /@media \(max-width: 767px\)[\s\S]*\.Header-primary \.item-flatrateMobileBrandDrawer\s*\{[\s\S]*display: block;/,
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
  assert.match(extendPhp, /js\/dist\/mobile-brand-drawer\.js/);
  assert.match(extendPhp, /js\/dist\/forum\.js/);
});
