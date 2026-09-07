#!/usr/bin/env node
/**
 * FORUM-SUB-001 — static Node gates for family follow notification inheritance.
 */
import assert from "node:assert/strict";
import { readFile, readdir } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const packageDir = new URL("../", import.meta.url);
const root = fileURLToPath(packageDir);

async function text(rel) {
  return readFile(new URL(rel, packageDir), "utf8");
}

test("FORUM-SUB-001: Conditional Follow Tags registration in extend.php", async () => {
  const extension = await text("extend.php");
  assert.match(extension, /whenExtensionEnabled\(\s*'fof-follow-tags'/);
  assert.match(extension, /FollowTagsFamilyServiceProvider::class/);
  assert.match(extension, /FilterInheritedIgnoredTagMentions::class/);
  assert.match(extension, /DeliverableEmailNotificationDriver::class/);
  assert.match(extension, /FamilyAwareNotificationSyncer|before parent::sync/);
});

test("FORUM-SUB-001: family classes do not write tag_user", async () => {
  const dir = path.join(root, "src/Subscription");
  const files = await readdir(dir);
  for (const file of files) {
    if (!file.endsWith(".php")) continue;
    const src = await text(`src/Subscription/${file}`);
    const withoutComments = src
      .replace(/\/\/.*$/gm, "")
      .replace(/\/\*[\s\S]*?\*\//g, "");
    assert.doesNotMatch(
      withoutComments,
      /insertInto\(\s*['"]tag_user['"]|table\(\s*['"]tag_user['"]\s*\)[\s\S]{0,80}->(insert|update|delete)\s*\(/i,
    );
    assert.doesNotMatch(withoutComments, /TagState::.*(create|save)|->save\(\s*\)/);
  }
});

test("FORUM-SUB-001: Follow Tags email views unchanged / teaser-safe", async () => {
  const nd = await text("views/fof-follow-tags/emails/newDiscussion.blade.php");
  const np = await text("views/fof-follow-tags/emails/newPost.blade.php");
  assert.doesNotMatch(nd, /post_content|\{post\}/);
  assert.doesNotMatch(np, /post_content|\{post\}/);
});

test("FORUM-SUB-001: IA-013 product assets untouched by family feature allowlist check", async () => {
  // Presence check only — family feature must not rewrite these paths.
  for (const rel of [
    "js/dist/forum-navigation.js",
    "js/dist/forum.js",
    "js/dist/mobile-brand-drawer.js",
    "resources/less/forum.less",
    "resources/less/mobile-brand-drawer.less",
  ]) {
    await text(rel);
  }
});

test("FORUM-SUB-001: NotificationSyncer subclass resolves before parent::sync", async () => {
  const syncer = await text("src/Subscription/FamilyAwareNotificationSyncer.php");
  assert.match(syncer, /extends NotificationSyncer/);
  assert.match(syncer, /\$this->resolver->resolve/);
  assert.match(syncer, /\$this->syncWithParent\(\s*\$blueprint\s*,\s*\$resolved\s*\)/);
  assert.match(syncer, /parent::sync\(\s*\$blueprint\s*,\s*\$users\s*\)/);
  const withoutComments = syncer.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
  assert.doesNotMatch(withoutComments, /beforeSending/);
});

test("FORUM-SUB-001: visibility checks fail closed on exception", async () => {
  const resolver = await text("src/Subscription/FollowTagsFamilyRecipientResolver.php");
  assert.match(resolver, /\$discussionVisible\s*=\s*false/);
  assert.match(resolver, /\$postVisible\s*=\s*false/);
  assert.match(resolver, /function isDiscussionVisibleTo/);
  assert.match(resolver, /function isPostVisibleTo/);
  assert.doesNotMatch(
    resolver,
    /catch\s*\([^)]*Throwable[^)]*\)\s*\{\s*\$discussionVisible\s*=\s*true/,
  );
  assert.doesNotMatch(
    resolver,
    /catch\s*\([^)]*Throwable[^)]*\)\s*\{\s*\$postVisible\s*=\s*true/,
  );
});

test("FORUM-SUB-001: composer.json has no hard Follow Tags dependency", async () => {
  const composer = JSON.parse(await text("composer.json"));
  const require = { ...(composer.require || {}), ...(composer["require-dev"] || {}) };
  assert.equal(Object.prototype.hasOwnProperty.call(require, "fof/follow-tags"), false);
});
