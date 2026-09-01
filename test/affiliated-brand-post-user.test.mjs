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

  add(name, item, priority) {
    this.items.set(name, { item, priority });
  }

  has(name) {
    return this.items.has(name);
  }

  get(name) {
    return this.items.get(name);
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
    children: children ?? [],
  };
}

function masqueradeField({
  id = "3",
  name = "Affiliated Brand",
  type = "select",
  deleted_at = null,
}) {
  return {
    id: () => id,
    attribute: (key) => {
      if (key === "name") return name;
      if (key === "type") return type;
      if (key === "deleted_at") return deleted_at;
      return undefined;
    },
  };
}

function masqueradeAnswer({ fieldId = "3", content = "Toyota" }) {
  return {
    attribute: (key) => {
      if (key === "fieldId") return fieldId;
      if (key === "content") return content;
      return undefined;
    },
  };
}

function user({ answers = [] } = {}) {
  return {
    masqueradeAnswers: () => answers,
  };
}

async function brandRuntime(options = {}) {
  const {
    mode = "flarum1",
    masqueradeFields = [masqueradeField({})],
    userModel = user({ answers: [masqueradeAnswer({ content: "Toyota" })] }),
  } = options;

  const bundle = await text("js/dist/forum.js");
  const initializers = new Map();

  class PostUser {
    userViewItems(user) {
      return new ItemList();
    }
  }

  const compat =
    mode === "flarum1"
      ? {
          extend: { extend: flarumExtend },
          "components/PostUser": PostUser,
        }
      : {
          "flarum/common/extend": { extend: flarumExtend },
          "flarum/forum/components/PostUser": PostUser,
        };

  const app = {
    initializers: {
      add(name, initializer) {
        initializers.set(name, initializer);
      },
    },
    store: {
      all(type) {
        if (type === "masquerade-field") {
          return {
            filter(callback) {
              return masqueradeFields.filter(callback);
            },
          };
        }

        return [];
      },
    },
  };

  runInNewContext(bundle, {
    app,
    flarum: { core: { compat } },
    m: mithril,
    module: { exports: {} },
  });

  const initializer = initializers.get("flatrate-wiki-affiliated-brand");
  assert.equal(typeof initializer, "function");
  initializer();

  return { PostUser, userModel };
}

test("bundle resolves Flarum 1.x PostUser compat key", async () => {
  const bundle = await text("js/dist/forum.js");

  assert.match(bundle, /compat\['components\/PostUser'\]/);
  assert.match(bundle, /compat\['flarum\/forum\/components\/PostUser'\]/);
});

test("affiliated brand field resolves by exact name and select type", async () => {
  const { PostUser, userModel } = await brandRuntime();
  const postUser = new PostUser();
  const items = postUser.userViewItems(userModel);

  assert.equal(items.has("flatrateAffiliatedBrand"), true);
  const entry = items.get("flatrateAffiliatedBrand");
  assert.equal(entry.priority, 95);
  assert.equal(entry.item.selector, "span.FlatRateAffiliatedBrand");
  assert.equal(entry.item.children[0], "Toyota");
});

test("namespaced PostUser compat fallback is supported", async () => {
  const { PostUser, userModel } = await brandRuntime({ mode: "namespaced" });
  const items = new PostUser().userViewItems(userModel);

  assert.equal(items.has("flatrateAffiliatedBrand"), true);
});

test("zero matching fields fail closed", async () => {
  const { PostUser } = await brandRuntime({ masqueradeFields: [] });
  const items = new PostUser().userViewItems(
    user({ answers: [masqueradeAnswer({ fieldId: "3", content: "Toyota" })] }),
  );

  assert.equal(items.has("flatrateAffiliatedBrand"), false);
});

test("duplicate matching fields fail closed", async () => {
  const duplicateFields = [masqueradeField({ id: "1" }), masqueradeField({ id: "2" })];
  const { PostUser } = await brandRuntime({ masqueradeFields: duplicateFields });
  const items = new PostUser().userViewItems(user({ answers: [masqueradeAnswer({ fieldId: "1" })] }));

  assert.equal(items.has("flatrateAffiliatedBrand"), false);
});

test("deleted field fails closed", async () => {
  const { PostUser } = await brandRuntime({
    masqueradeFields: [masqueradeField({ deleted_at: "2026-01-01" })],
  });
  const items = new PostUser().userViewItems(
    user({ answers: [masqueradeAnswer({ fieldId: "3", content: "Toyota" })] }),
  );

  assert.equal(items.has("flatrateAffiliatedBrand"), false);
});

test("wrong field type fails closed", async () => {
  const { PostUser } = await brandRuntime({
    masqueradeFields: [masqueradeField({ type: "text" })],
  });
  const items = new PostUser().userViewItems(
    user({ answers: [masqueradeAnswer({ fieldId: "3", content: "Toyota" })] }),
  );

  assert.equal(items.has("flatrateAffiliatedBrand"), false);
});

test("blank trimmed answer suppresses brand line", async () => {
  const { PostUser } = await brandRuntime({
    userModel: user({ answers: [masqueradeAnswer({ content: "   " })] }),
  });
  const items = new PostUser().userViewItems(
    user({ answers: [masqueradeAnswer({ content: "   " })] }),
  );

  assert.equal(items.has("flatrateAffiliatedBrand"), false);
});

test("missing masqueradeAnswers suppresses brand line", async () => {
  const { PostUser } = await brandRuntime();
  const bareUser = {};
  const items = new PostUser().userViewItems(bareUser);

  assert.equal(items.has("flatrateAffiliatedBrand"), false);
});

test("affiliated brand renderer does not fetch or mutate tags or markers", async () => {
  const bundle = await text("js/dist/forum.js");
  const css = await text("resources/less/forum.less");
  const brandBlock = bundle.split("flatrate-wiki-affiliated-brand")[1] || "";

  assert.doesNotMatch(brandBlock, /\bfetch\s*\(/);
  assert.doesNotMatch(brandBlock, /XMLHttpRequest/);
  assert.doesNotMatch(brandBlock, /app\.request\(/);
  assert.doesNotMatch(brandBlock, /discussion\.tags\(/);
  assert.doesNotMatch(brandBlock, /flatRateJobBreakdown/);
  assert.doesNotMatch(css, /\.Post-header\s*>\s*ul\s*\{[^}]*display:\s*flex;/s);
  assert.doesNotMatch(css, /\.TagLabel\s*\{[^}]*float:\s*right/s);
});

test("scoped affiliated brand styles avoid global post header layout changes", async () => {
  const css = await text("resources/less/forum.less");

  assert.match(css, /\.PostUser\s+\.FlatRateAffiliatedBrand/);
  assert.doesNotMatch(css, /\.Post-header\s*>\s*ul\s*\{[^}]*display:\s*flex;/s);
});
