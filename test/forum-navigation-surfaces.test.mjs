import assert from "node:assert/strict";
import { createHash } from "node:crypto";
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

  toArray() {
    return [...this.items.entries()]
      .sort((left, right) => right[1].priority - left[1].priority)
      .map(([name, entry]) => ({ name, item: entry.item, priority: entry.priority }));
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
  runInNewContext(await text("js/dist/forum-navigation.js"), context);
  return context.module.exports;
}

function tagsForContract(contract) {
  const tags = [];
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

function extractGroupedNavInitializer(source) {
  const marker = "/*! FlatRate Wiki desktop IndexPage/DiscussionPage sidebar forum navigation. */";
  const start = source.indexOf(marker);
  assert.ok(start > -1, "grouped-nav initializer marker missing");
  return source.slice(start);
}

async function surfaceRuntime({ tags = [], includeDiscussionPage = true } = {}) {
  const shared = await text("js/dist/forum-navigation.js");
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

  class DiscussionPage {
    sidebarItems() {
      const items = new ItemList();
      items.add("controls", { selector: "SplitDropdown" }, 100);
      items.add("scrubber", { selector: "PostStreamScrubber" }, -100);
      return items;
    }
  }

  class LinkButton {}

  const compat = {
    extend: { extend: flarumExtend },
    "components/IndexPage": IndexPage,
    "components/LinkButton": LinkButton,
  };
  if (includeDiscussionPage) {
    compat["components/DiscussionPage"] = DiscussionPage;
  }

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
    m: createMithril(""),
    module: { exports: {} },
  });

  const initializer = initializers.get("flatrate-wiki-forum-navigation-sidebar");
  assert.equal(typeof initializer, "function");
  initializer();

  return { IndexPage, DiscussionPage, LinkButton };
}

test("exact Flarum 1.8.19 DiscussionPage exposes sidebarItems seam", async () => {
  const source = await text("test/fixtures/flarum-1.8.19-DiscussionPage.tsx");
  const provenance = await text("test/fixtures/flarum-1.8.19-DiscussionPage.SOURCE.txt");
  const digest = createHash("sha256").update(source).digest("hex");

  assert.match(provenance, /repo: https:\/\/github.com\/flarum\/framework/);
  assert.match(provenance, /tag: v1\.8\.19/);
  assert.match(
    provenance,
    /path: framework\/core\/js\/src\/forum\/components\/DiscussionPage\.tsx/,
  );
  assert.match(provenance, new RegExp(`SHA256: ${digest}`));
  assert.equal(digest, "6fd54b5a0e07dffaf5da8ead70a9ee1a51903f045864bb33318a8f535830ec23");

  assert.match(source, /export default class DiscussionPage(?:<[^>]*>)?\s+extends Page/);
  assert.match(source, /sidebar\(\):\s*Mithril\.Children/);
  assert.match(source, /className="DiscussionPage-nav"/);
  assert.match(source, /sidebarItems\(\)/);
  assert.match(source, /mainContent\(\):\s*ItemList/);
  assert.match(source, /items\.add\('sidebar',\s*this\.sidebar\(\)/);
  assert.match(source, /items\.add\(\s*'controls'/);
  assert.match(source, /items\.add\('scrubber'/);
  assert.match(source, /DiscussionControls/);
  assert.match(source, /PostStreamScrubber/);
});

test("IndexPage and DiscussionPage both receive one grouped-nav ItemList entry", async () => {
  const contract = await sharedNavigationContract();
  const { IndexPage, DiscussionPage } = await surfaceRuntime({
    tags: tagsForContract(contract),
  });

  const indexItems = new IndexPage().sidebarItems();
  const discussionItems = new DiscussionPage().sidebarItems();

  assert.equal(indexItems.has("flatrateForumNavigation"), true);
  assert.equal(discussionItems.has("flatrateForumNavigation"), true);
  assert.equal(
    [...indexItems.items.keys()].filter((name) => name === "flatrateForumNavigation").length,
    1,
  );
  assert.equal(
    [...discussionItems.items.keys()].filter((name) => name === "flatrateForumNavigation").length,
    1,
  );
  assert.equal(indexItems.getPriority("flatrateForumNavigation"), -20);
  assert.equal(discussionItems.getPriority("flatrateForumNavigation"), -200);

  const ordered = discussionItems.toArray().map((entry) => entry.name);
  assert.deepEqual(ordered.slice(0, 3), ["controls", "scrubber", "flatrateForumNavigation"]);
  assert.ok(discussionItems.getPriority("controls") > discussionItems.getPriority("flatrateForumNavigation"));
  assert.ok(discussionItems.getPriority("scrubber") > discussionItems.getPriority("flatrateForumNavigation"));
  assert.equal(discussionItems.has("controls"), true);
  assert.equal(discussionItems.has("scrubber"), true);

  const indexNav = indexItems.get("flatrateForumNavigation");
  const discussionNav = discussionItems.get("flatrateForumNavigation");
  assert.equal(
    indexNav.selector,
    "nav.FlatRateForumNav.FlatRateForumNav--sidebar.FlatRateForumNav--index",
  );
  assert.equal(
    discussionNav.selector,
    "nav.FlatRateForumNav.FlatRateForumNav--sidebar.FlatRateForumNav--discussion",
  );
  assert.deepEqual(
    plain(indexNav.children.map((section) => section.attrs["data-group"])),
    ["community", "technician-topics", "brands"],
  );
  assert.deepEqual(
    plain(discussionNav.children.map((section) => section.attrs["data-group"])),
    ["community", "technician-topics", "brands"],
  );
  assert.equal(
    discussionNav.children
      .flatMap((section) => section.children[1].children)
      .some((item) => item.children[0].attrs.active),
    false,
  );
});

test("missing DiscussionPage compat fails closed without crashing IndexPage", async () => {
  const contract = await sharedNavigationContract();
  const { IndexPage } = await surfaceRuntime({
    tags: tagsForContract(contract),
    includeDiscussionPage: false,
  });

  const items = new IndexPage().sidebarItems();
  assert.equal(items.has("flatrateForumNavigation"), true);
  assert.equal(items.getPriority("flatrateForumNavigation"), -20);
});

test("grouped-nav initializer avoids direct DOM mutation patterns", async () => {
  const grouped = extractGroupedNavInitializer(await text("js/dist/forum.js"));

  assert.doesNotMatch(grouped, /document\.querySelector\(/);
  assert.doesNotMatch(grouped, /appendChild\(/);
  assert.doesNotMatch(grouped, /insertBefore\(/);
  assert.doesNotMatch(grouped, /MutationObserver\(/);
  assert.doesNotMatch(grouped, /setInterval\(/);
  assert.match(grouped, /extend\(DiscussionPage\.prototype, 'sidebarItems'/);
  assert.match(grouped, /extend\(IndexPage\.prototype, 'sidebarItems'/);
  assert.doesNotMatch(grouped, /pageContent|hero\(/);
});

test("responsive CSS hides both desktop surfaces below 768 and scrolls discussion nav", async () => {
  const less = await text("resources/less/forum.less");

  assert.match(less, /\.DiscussionPage-nav > ul > \.item-flatrateForumNavigation/);
  assert.match(less, /@media \(min-width: 768px\)/);
  assert.match(
    less,
    /\.FlatRateForumNav--sidebar,\s*\.IndexPage-nav > ul > \.item-flatrateForumNavigation,\s*\.DiscussionPage-nav > ul > \.item-flatrateForumNavigation[\s\S]*display: none;/,
  );
  assert.match(
    less,
    /@media \(min-width: 768px\)[\s\S]*\.IndexPage-nav > ul > \.item-flatrateForumNavigation,\s*\.DiscussionPage-nav > ul > \.item-flatrateForumNavigation[\s\S]*display: block;/,
  );
  assert.match(less, /\.FlatRateForumNav--discussion\s*\{[\s\S]*max-height:[\s\S]*overflow-y:\s*auto;/);
  assert.doesNotMatch(less, /\.DiscussionPage-nav\s*\{[^}]*overflow\s*:/);
});
