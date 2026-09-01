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
  if (arguments.length === 2 && (Array.isArray(attrsOrChildren) || typeof attrsOrChildren === "string")) {
    return { selector, attrs: {}, children: attrsOrChildren };
  }

  return {
    selector,
    attrs: attrsOrChildren || {},
    children: children ?? [],
  };
}

function post({ number, marked = false }) {
  return {
    number: () => number,
    attribute: (name) => (name === "flatRateJobBreakdown" ? marked : undefined),
  };
}

function tag({
  id = "7",
  slug = "job-breakdown",
  name = "Job Breakdown",
  color = "#16a34a",
  icon = "fas fa-wrench",
  child = true,
}) {
  return {
    id: () => id,
    slug: () => slug,
    name: () => name,
    color: () => color,
    icon: () => icon,
    isChild: () => child,
  };
}

async function markerRuntime(options = {}) {
  const {
    mode = "flarum1",
    tags = [tag({})],
    tagLabelImpl = null,
    includeTagLabel = true,
  } = options;

  const bundle = await text("js/dist/forum.js");
  const initializers = new Map();
  const tagLabelCalls = [];

  class ReplyComposer {
    oninit() {}

    headerItems() {
      return new ItemList();
    }

    data() {
      return { content: "reply" };
    }
  }

  class EditPostComposer {
    constructor(attrs) {
      this.attrs = attrs;
    }

    oninit() {}

    headerItems() {
      return new ItemList();
    }

    data() {
      return { content: "edit" };
    }
  }

  class CommentPost {
    constructor(attrs) {
      this.attrs = attrs;
    }

    headerItems() {
      return new ItemList();
    }
  }

  const tagLabel = tagLabelImpl
    ? (selectedTag, attrs) => {
        tagLabelCalls.push({ tag: selectedTag, attrs });
        return tagLabelImpl(selectedTag, attrs);
      }
    : (selectedTag, attrs) => {
        tagLabelCalls.push({ tag: selectedTag, attrs });
        return { selector: "span.TagLabel", attrs: {}, children: [selectedTag.name()] };
      };

  const compat =
    mode === "flarum1"
      ? {
          extend: { extend: flarumExtend },
          "components/ReplyComposer": ReplyComposer,
          "components/EditPostComposer": EditPostComposer,
          "components/CommentPost": CommentPost,
        }
      : {
          "flarum/common/extend": { extend: flarumExtend },
          "flarum/forum/components/ReplyComposer": ReplyComposer,
          "flarum/forum/components/EditPostComposer": EditPostComposer,
          "flarum/forum/components/CommentPost": CommentPost,
        };

  if (includeTagLabel) {
    compat["tags/helpers/tagLabel"] = tagLabel;
  }

  const app = {
    initializers: {
      add(name, initializer) {
        initializers.set(name, initializer);
      },
    },
    store: {
      all(type) {
        return type === "tags"
          ? {
              filter(callback) {
                return tags.filter(callback);
              },
            }
          : [];
      },
    },
  };

  runInNewContext(bundle, {
    app,
    flarum: { core: { compat } },
    m: mithril,
    module: { exports: {} },
  });

  const initializer = initializers.get("flatrate-wiki-reply-job-breakdown");
  assert.equal(typeof initializer, "function");
  initializer();

  return { ReplyComposer, EditPostComposer, CommentPost, tagLabelCalls };
}

test("extension registers the reply marker listeners and post serializer attribute", async () => {
  const extension = await text("extend.php");

  assert.match(extension, /use Flarum\\Api\\Serializer\\PostSerializer;/);
  assert.match(extension, /use Flarum\\Post\\Event\\Saving;/);
  assert.match(extension, /use Flarum\\Post\\Event\\Deleted;/);
  assert.match(extension, /->listen\(Saving::class, Markers\\SaveJobBreakdownMarker::class\)/);
  assert.match(extension, /->listen\(Deleted::class, Markers\\DeletePostMarkers::class\)/);
  assert.match(extension, /new Extend\\ApiSerializer\(PostSerializer::class\)/);
  assert.match(extension, /->attribute\('flatRateJobBreakdown', Api\\SerializePostJobBreakdownMarker::class\)/);
});

test("post marker migration stores a controlled reply marker with post cleanup", async () => {
  const migration = await text("migrations/2026_08_30_000000_create_post_markers.php");

  assert.match(migration, /flatrate_post_markers/);
  assert.match(migration, /post_id/);
  assert.match(migration, /marker_key/);
  assert.match(migration, /primary\(\['post_id', 'marker_key'\]\)/);
  assert.match(migration, /references\('id'\)\s*->on\('posts'\)\s*->onDelete\('cascade'\)/);
  assert.match(migration, /references\('id'\)\s*->on\('users'\)\s*->onDelete\('set null'\)/);
});

test("backend accepts only reply post marker requests and serializes a boolean", async () => {
  const saver = await text("src/Markers/SaveJobBreakdownMarker.php");
  const serializer = await text("src/Api/SerializePostJobBreakdownMarker.php");
  const store = await text("src/Markers/PostMarkerStore.php");
  const deleter = await text("src/Markers/DeletePostMarkers.php");

  assert.match(saver, /flatRateJobBreakdown/);
  assert.match(saver, /assertRegistered\(\)/);
  assert.match(saver, /instanceof CommentPost/);
  assert.match(saver, /assertPermission\(\$enabled !== null\)/);
  assert.match(saver, /assertCan\('edit', \$post\)/);
  assert.match(saver, /afterSave/);
  assert.match(saver, /type'\]\s*\?\?\s*null\) === 'discussions'/);
  assert.match(saver, /first_post_id/);
  assert.match(serializer, /PostSerializer/);
  assert.match(serializer, /instanceof CommentPost/);
  assert.match(serializer, /hasJobBreakdown\(\(int\) \$post->id\)/);
  assert.match(store, /JOB_BREAKDOWN = 'job-breakdown'/);
  assert.match(store, /flatrate_post_markers/);
  assert.match(store, /setJobBreakdown\(Post \$post, User \$actor, bool \$enabled\)/);
  assert.match(deleter, /deleteForPost\(\(int\) \$event->post->id\)/);
});

test("forum bundle uses Flarum TagLabel helper and stable slug lookup", async () => {
  const bundle = await text("js/dist/forum.js");
  const css = await text("resources/less/forum.less");
  const readme = await text("README.md");

  assert.match(bundle, /compat\['tags\/helpers\/tagLabel'\]/);
  assert.match(bundle, /job-breakdown/);
  assert.match(bundle, /flatrateJobBreakdownTag/);
  assert.doesNotMatch(bundle, /FlatRateReplyJobBreakdownBadge/);
  assert.doesNotMatch(bundle, /data\.attributes\.flatRateJobBreakdown/);
  assert.match(bundle, /data\.flatRateJobBreakdown/);
  assert.match(css, /\.FlatRateReplyJobBreakdownToggle/);
  assert.doesNotMatch(css, /\.FlatRateReplyJobBreakdownBadge/);
  assert.match(readme, /TagLabel presentation/i);
  assert.match(readme, /discussion-level/i);
});

test("Flarum 1.8-shaped compat executes the reply marker initializer end to end", async () => {
  const { ReplyComposer, EditPostComposer, CommentPost, tagLabelCalls } = await markerRuntime();

  const reply = new ReplyComposer();
  reply.oninit();
  assert.equal(reply.flatRateJobBreakdown, false);

  const replyHeader = reply.headerItems();
  assert.equal(replyHeader.has("flatrateJobBreakdown"), true);
  const toggle = replyHeader.get("flatrateJobBreakdown").item;
  assert.equal(toggle.selector, "label.FlatRateReplyJobBreakdownToggle");

  toggle.children[0].attrs.onchange({ target: { checked: true } });
  assert.equal(reply.flatRateJobBreakdown, true);
  assert.equal(reply.data().flatRateJobBreakdown, true);
  assert.equal(reply.data().attributes, undefined);

  const markedReply = post({ number: 2, marked: true });
  const editReply = new EditPostComposer({ post: markedReply });
  editReply.oninit();
  assert.equal(editReply.flatRateJobBreakdown, true);
  assert.equal(editReply.data().flatRateJobBreakdown, true);

  const starter = new EditPostComposer({ post: post({ number: 1, marked: true }) });
  starter.oninit();
  assert.equal(starter.headerItems().has("flatrateJobBreakdown"), false);
  assert.equal(starter.data().flatRateJobBreakdown, undefined);

  const markedPost = new CommentPost({ post: markedReply });
  const markedHeader = markedPost.headerItems();
  assert.equal(markedHeader.has("flatrateJobBreakdownTag"), true);
  assert.equal(markedHeader.get("flatrateJobBreakdownTag").item.selector, "span.TagLabel");
  assert.equal(tagLabelCalls.length, 1);
  assert.equal(tagLabelCalls[0].tag.slug(), "job-breakdown");

  const ordinaryPost = new CommentPost({ post: post({ number: 3, marked: false }) });
  assert.equal(ordinaryPost.headerItems().has("flatrateJobBreakdownTag"), false);
  assert.equal(tagLabelCalls.length, 1);
});

test("marked reply resolves canonical tag by slug and passes Tag model to tagLabel", async () => {
  const canonical = tag({
    slug: "job-breakdown",
    name: "Repair Breakdown",
    color: "#123456",
    icon: "fas fa-tools",
  });
  const { CommentPost, tagLabelCalls } = await markerRuntime({ tags: [canonical] });
  const markedPost = new CommentPost({ post: post({ number: 4, marked: true }) });
  markedPost.headerItems();

  assert.equal(tagLabelCalls.length, 1);
  assert.equal(tagLabelCalls[0].tag.slug(), "job-breakdown");
  assert.equal(tagLabelCalls[0].tag.name(), "Repair Breakdown");
  assert.equal(tagLabelCalls[0].tag.color(), "#123456");
  assert.equal(tagLabelCalls[0].tag.icon(), "fas fa-tools");
});

test("marker true without canonical tag renders no badge and does not crash", async () => {
  const { CommentPost, tagLabelCalls } = await markerRuntime({ tags: [tag({ slug: "other-tag" })] });
  const markedPost = new CommentPost({ post: post({ number: 5, marked: true }) });
  const header = markedPost.headerItems();

  assert.equal(header.has("flatrateJobBreakdownTag"), false);
  assert.equal(tagLabelCalls.length, 0);
});

test("marker true without tags helper fails safely", async () => {
  const { CommentPost } = await markerRuntime({ includeTagLabel: false });
  const markedPost = new CommentPost({ post: post({ number: 6, marked: true }) });
  const header = markedPost.headerItems();

  assert.equal(header.has("flatrateJobBreakdownTag"), false);
});

test("namespaced compatibility fallbacks remain supported", async () => {
  const { ReplyComposer } = await markerRuntime({ mode: "namespaced" });
  const reply = new ReplyComposer();
  reply.oninit();

  assert.equal(reply.headerItems().has("flatrateJobBreakdown"), true);
  reply.flatRateJobBreakdown = true;
  assert.equal(reply.data().flatRateJobBreakdown, true);
});

test("reply marker handling does not mutate discussion tags", async () => {
  const bundle = await text("js/dist/forum.js");

  assert.doesNotMatch(bundle, /discussion\.tags\(/);
  assert.doesNotMatch(bundle, /discussion\.save\(/);
  assert.doesNotMatch(bundle, /relationships\.tags/);
  assert.doesNotMatch(bundle, /tagIds/);
});
