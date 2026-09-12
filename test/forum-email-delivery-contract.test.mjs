#!/usr/bin/env node
/**
 * FORUM-EMAIL-002 — static contract gates for synthetic recipient suppression
 * and one-way confirmed-email promotion.
 */
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);

async function text(path) {
  return readFile(new URL(path, packageDir), "utf8");
}

test("extend.php registers FlatRate email driver and no global beforeSending filter", async () => {
  const extension = await text("extend.php");
  const withoutComments = extension.replace(/\/\/.*$/gm, "");
  assert.match(extension, /new Extend\\Notification\(\)/);
  assert.match(
    extension,
    /->driver\(\s*['"]email['"]\s*,\s*Notification\\DeliverableEmailNotificationDriver::class\s*\)/,
  );
  // Reserved-email path must not use a global beforeSending filter.
  // FORUM-SUB-001 may register a Conditional mention-ignore beforeSending
  // only when fof-follow-tags is enabled.
  assert.equal(beforeSendingCountOutsideFollowTagsConditional(withoutComments), 0);
});

test("DeliverableEmailNotificationDriver filters via ForumEmailPolicy and delegates to core", async () => {
  const driver = await text("src/Notification/DeliverableEmailNotificationDriver.php");
  assert.match(driver, /implements NotificationDriverInterface/);
  assert.match(driver, /private EmailNotificationDriver \$inner/);
  assert.match(driver, /ForumEmailPolicy::isDeliverable/);
  assert.match(driver, /\$this->inner->send\(\$blueprint, \$deliverable\)/);
  assert.match(driver, /\$this->inner->registerType\(\$blueprintClass, \$driversEnabledByDefault\)/);
  assert.doesNotMatch(driver, /beforeSending/);
  assert.doesNotMatch(driver, /NotificationSyncer/);
});

test("ForumEmailPolicy reserves the whole users.flatrate.wiki namespace", async () => {
  const policy = await text("src/Identity/ForumEmailPolicy.php");
  assert.match(policy, /INTERNAL_DOMAIN\s*=\s*'users\.flatrate\.wiki'/);
  assert.match(policy, /function isInternal/);
  assert.match(policy, /function isDeliverable/);
  assert.match(policy, /function canPromote/);
  assert.match(policy, /strcasecmp\(\$domain, self::INTERNAL_DOMAIN\)/);
  assert.match(policy, /FILTER_VALIDATE_EMAIL/);
});

test("FlatRateUserProvisioner reconciles linked users with one-way promotion only", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /reconcileLinkedEmail\(/);
  assert.match(provisioner, /if \(\$linked = \$this->linkedUser\(\$sub\)\) \{\s*return \$this->finishLinkedUser\(/s);
  assert.match(provisioner, /function finishLinkedUser[\s\S]*reconcileLinkedEmail\(/s);
  assert.match(provisioner, /ForumEmailPolicy::canPromote/);
  assert.match(provisioner, /->changeEmail\(\$incomingEmail\)/);
  assert.match(provisioner, /->activate\(\)/);
  assert.match(provisioner, /existing_account_requires_explicit_link/);
  assert.match(provisioner, /lockForUpdate\(\)/);

  // Collision must preserve the linked placeholder user, not create/merge.
  assert.match(provisioner, /\$collision = User::query\(\)/);
  assert.match(provisioner, /where\('id', '!=', \$user->id\)/);

  // No real->placeholder or real A->real B auto mutation helpers.
  assert.doesNotMatch(provisioner, /changeEmail\(\s*\$currentEmail\s*\)/);
  assert.doesNotMatch(provisioner, /downgrade/);
  assert.doesNotMatch(provisioner, /replaceRealEmail/);
});

test("promotion save race preserves placeholder only after ownership collision is proven", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  const policy = await text("src/Identity/ForumEmailPolicy.php");

  assert.match(provisioner, /function reconcileLinkedEmail[\s\S]*catch \(QueryException \$error\)/s);
  assert.match(provisioner, /recoverLinkedUserAfterPromotionRace\(/);
  assert.match(provisioner, /\$persisted = User::find\(\$linked->id\)/);
  assert.match(provisioner, /if \(! \$persisted\) \{\s*throw \$error;/s);
  assert.match(
    provisioner,
    /\$incomingOwnedByAnotherUser = User::query\(\)[\s\S]*->where\('email', \$incomingEmail\)[\s\S]*->where\('id', '!=', \$persisted->id\)[\s\S]*->exists\(\)/s,
  );
  assert.match(provisioner, /ForumEmailPolicy::isPreservablePromotionRace\(/);
  assert.match(
    provisioner,
    /if \(ForumEmailPolicy::isPreservablePromotionRace\([\s\S]*\)\) \{\s*return \$persisted;\s*\}\s*throw \$error;/s,
  );

  // Must not swallow every QueryException unconditionally.
  assert.doesNotMatch(
    provisioner,
    /catch \(QueryException \$error\) \{\s*return \$linked;\s*\}/s,
  );
  assert.doesNotMatch(
    provisioner,
    /catch \(QueryException \$error\) \{\s*return \$this->linkedUser/s,
  );

  assert.match(policy, /function isPreservablePromotionRace/);
  assert.match(policy, /return self::isInternal\(\$persistedEmail\) && \$incomingOwnedByAnotherUser/);
});

test("placeholder outbound suppression does not use a global recipient filter", async () => {
  const extension = await text("extend.php");
  const withoutComments = extension.replace(/\/\/.*$/gm, "");
  const driver = await text("src/Notification/DeliverableEmailNotificationDriver.php");
  assert.match(extension, /DeliverableEmailNotificationDriver::class/);
  assert.match(driver, /Filters only the outbound email driver recipients/);
  const emailDriverBlock = withoutComments.match(
    /\(new Extend\\Notification\(\)\)\s*->driver\(\s*'email',\s*Notification\\DeliverableEmailNotificationDriver::class\s*\)/,
  );
  assert.ok(emailDriverBlock);
  assert.equal(beforeSendingCountOutsideFollowTagsConditional(withoutComments), 0);
  assert.match(
    withoutComments,
    /whenExtensionEnabled\(\s*'fof-follow-tags'[\s\S]*?->beforeSending\(\s*FilterInheritedIgnoredTagMentions::class\s*\)/,
  );
});

function beforeSendingCountOutsideFollowTagsConditional(src) {
  const withoutConditional = src.replace(
    /\(new Extend\\Conditional\(\)\)\s*->whenExtensionEnabled\(\s*'fof-follow-tags'\s*,\s*\[[\s\S]*?\]\s*\)\s*,/,
    "",
  );
  return [...withoutConditional.matchAll(/->beforeSending\s*\(/g)].length;
}
