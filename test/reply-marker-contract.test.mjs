import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

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

test("forum UI exposes the reply marker without changing discussion tags", async () => {
  const bundle = await text("js/dist/forum.js");
  const css = await text("resources/less/forum.less");
  const readme = await text("README.md");

  assert.match(bundle, /flatrate-wiki-reply-job-breakdown/);
  assert.match(bundle, /ReplyComposer/);
  assert.match(bundle, /EditPostComposer/);
  assert.match(bundle, /CommentPost/);
  assert.match(bundle, /flatRateJobBreakdown/);
  assert.match(bundle, /m\('span\.FlatRateReplyJobBreakdownBadge', 'Job Breakdown'\)/);
  assert.doesNotMatch(bundle, /DiscussionComposer/);
  assert.match(css, /\.FlatRateReplyJobBreakdownToggle/);
  assert.match(css, /\.FlatRateReplyJobBreakdownBadge/);
  assert.match(readme, /not as a Flarum tag/i);
  assert.match(readme, /not as Supabase profile data/i);
});
