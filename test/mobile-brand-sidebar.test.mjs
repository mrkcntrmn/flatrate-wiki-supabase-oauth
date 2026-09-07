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

function sidebarLinkNodes(group) {
  return group.children[1].children.flatMap((item) => {
    const parent = item.children[0];
    const children = item.children[1]?.children ?? [];
    return [parent, ...children.map((childItem) => childItem.children[0])];
  });
}

async function sidebarRuntime({ activeSlug = "toyota", tags = [] } = {}) {
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
    "components/DiscussionPage": DiscussionPage,
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

  const initializer = initializers.get("flatrate-wiki-forum-navigation-sidebar");
  assert.equal(typeof initializer, "function");
  initializer();

  return { IndexPage, DiscussionPage, LinkButton };
}

test("desktop forum navigation hooks IndexPage through Flarum compat", async () => {
  const bundle = await text("js/dist/forum.js");

  assert.match(bundle, /app\.initializers\.add\('flatrate-wiki-forum-navigation-sidebar'/);
  assert.match(bundle, /compat\['components\/IndexPage'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/IndexPage'\]/);
  assert.match(bundle, /compat\['components\/DiscussionPage'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/DiscussionPage'\]/);
  assert.match(bundle, /extend\(IndexPage\.prototype, 'sidebarItems'/);
  assert.match(bundle, /extend\(DiscussionPage\.prototype, 'sidebarItems'/);
  assert.match(bundle, /FlatRateForumNavigation/);
  assert.doesNotMatch(bundle, /var GROUPS = /);
  assert.doesNotMatch(bundle, /FlatRateBrandsNavigation/);
});

test("desktop sidebar renders Community, Technician Topics, and Brands from the shared contract", async () => {
  const contract = await sharedNavigationContract();
  const tags = tagsForContract(contract);
  assertProductionLikeTopology(tags);
  const { IndexPage, LinkButton } = await sidebarRuntime({
    activeSlug: "chevrolet",
    tags,
  });

  const items = new IndexPage().sidebarItems();
  assert.equal(items.has("flatrateForumNavigation"), true);
  assert.equal(items.getPriority("flatrateForumNavigation"), -20);

  const nav = items.get("flatrateForumNavigation");
  assert.equal(
    nav.selector,
    "nav.FlatRateForumNav.FlatRateForumNav--sidebar.FlatRateForumNav--index",
  );
  assert.equal(nav.attrs["aria-label"], "Forum navigation");
  assert.deepEqual(
    plain(groupSections(nav).map((section) => section.children[0].children[0])),
    ["Community", "Technician Topics", "Brands"],
  );
  assert.deepEqual(
    plain(groupSections(nav).map((section) => section.attrs["data-group"])),
    ["community", "technician-topics", "brands"],
  );

  const community = groupSections(nav).find((section) => section.attrs["data-group"] === "community");
  const technicianTopics = groupSections(nav).find(
    (section) => section.attrs["data-group"] === "technician-topics",
  );
  const brands = brandGroup(nav);
  assert.deepEqual(
    plain(community.children[1].children.map((item) => item.children[0].children[0])),
    ["Start Here"],
  );
  assert.deepEqual(
    plain(technicianTopics.children[1].children.map((item) => item.children[0].children[0])),
    ["General Shop Discussion"],
  );
  assert.deepEqual(
    plain(brands.children[1].children.map((item) => item.children[0].children[0])),
    plain(contract.getGroup("brands").children.map((node) => node.displayName)),
  );
  assert.deepEqual(
    plain(
      brands.children[1].children
        .find((item) => item.children[0].children[0] === "CDJR")
        .children[1].children.map((item) => item.children[0].children[0]),
    ),
    ["Chrysler", "Dodge", "Jeep", "Ram"],
  );
  assert.deepEqual(
    plain(
      brands.children[1].children
        .find((item) => item.children[0].children[0] === "GM")
        .children[1].children.map((item) => item.children[0].children[0]),
    ),
    ["Buick", "Cadillac", "Chevrolet", "GMC"],
  );
  assert.ok(sidebarLinkNodes(brands).every((link) => link.selector === LinkButton));
  assert.deepEqual(
    plain(sidebarLinkNodes(brands).map((link) => link.attrs.href).filter((href) => href === "/t/chevrolet")),
    ["/t/chevrolet"],
  );
  assert.deepEqual(
    plain(sidebarLinkNodes(brands).filter((link) => link.attrs.active).map((link) => link.children[0])),
    ["Chevrolet"],
  );
  assert.equal(
    brands.children[1].children.some((item) => item.children[0].children[0] === "Buick"),
    false,
  );
  assert.equal(sidebarLinkNodes(brands).length, 41);
  assert.deepEqual(
    plain(
      sidebarLinkNodes(brands)
        .filter((link) => link.attrs.href === "/t/alpha-romeo")
        .map((link) => link.children[0]),
    ),
    ["Alfa Romeo"],
  );
  assert.ok(
    sidebarLinkNodes(brands)
      .filter((link) =>
        ["Buick", "Cadillac", "Chevrolet", "GMC", "Chrysler", "Dodge", "Jeep", "Ram"].includes(link.children[0]),
      )
      .every((link) => {
        const slug = link.attrs.href.replace("/t/", "");
        const model = tags.find((candidate) => candidate.slug() === slug);
        return model && model.isChild() === false && model.parent() === null;
      }),
  );
});

test("desktop sidebar selects General Shop Discussion by active slug", async () => {
  const contract = await sharedNavigationContract();
  const { IndexPage } = await sidebarRuntime({
    activeSlug: "general-shop-discussion",
    tags: tagsForContract(contract),
  });

  const technicianTopics = groupSections(new IndexPage().sidebarItems().get("flatrateForumNavigation")).find(
    (section) => section.attrs["data-group"] === "technician-topics",
  );

  assert.deepEqual(
    plain(sidebarLinkNodes(technicianTopics).filter((link) => link.attrs.active).map((link) => link.children[0])),
    ["General Shop Discussion"],
  );
});

test("desktop sidebar fails closed when tag data is unavailable", async () => {
  const { IndexPage } = await sidebarRuntime({ tags: [] });
  const items = new IndexPage().sidebarItems();

  assert.equal(items.has("flatrateForumNavigation"), false);
  assert.equal(items.has("newDiscussion"), true);
  assert.equal(items.has("nav"), true);
});

test("desktop sidebar keeps Brands when one nested child tag is missing", async () => {
  const contract = await sharedNavigationContract();
  const { IndexPage } = await sidebarRuntime({
    tags: tagsForContract(contract).filter((candidate) => candidate.slug() !== "ram"),
  });
  const items = new IndexPage().sidebarItems();

  assert.equal(items.has("flatrateForumNavigation"), true);
  assert.equal(sidebarLinkNodes(brandGroup(items.get("flatrateForumNavigation"))).length, 40);
});

test("desktop sidebar styling is desktop-only and uses a single-column navigation list", async () => {
  const less = await text("resources/less/forum.less");

  assert.match(
    less,
    /\.FlatRateForumNav--sidebar,\s*\.IndexPage-nav > ul > \.item-flatrateForumNavigation,\s*\.DiscussionPage-nav > ul > \.item-flatrateForumNavigation/s,
  );
  assert.match(
    less,
    /@media \(min-width: 768px\)[\s\S]*\.IndexPage-nav > ul > \.item-flatrateForumNavigation,\s*\.DiscussionPage-nav > ul > \.item-flatrateForumNavigation[\s\S]*display: block;/,
  );
  assert.match(
    less,
    /\.FlatRateForumNav--discussion\s*\{[\s\S]*max-height:[\s\S]*overflow-y:\s*auto;/,
  );
  assert.match(
    less,
    /\.FlatRateForumNav--sidebar \.FlatRateForumNav-links,\s*\.FlatRateForumNav--sidebar \.FlatRateForumNav-children\s*\{[\s\S]*display: block;[\s\S]*width: 100%;/,
  );
  assert.doesNotMatch(less, /\.FlatRateForumNav--sidebar \.FlatRateForumNav-links[^{]*\{[^}]*grid-template-columns:/s);
  assert.match(less, /\.FlatRateForumNav--sidebar \.FlatRateForumNav-link\.Button\s*\{[\s\S]*width: 100%;[\s\S]*background: transparent;/);
  assert.match(less, /\.FlatRateForumNav--sidebar \.FlatRateForumNav-link\.Button\.active\s*\{[\s\S]*@primary-color/);
  assert.doesNotMatch(less, /\.DiscussionPage-nav\s*\{[^}]*overflow/);
});
