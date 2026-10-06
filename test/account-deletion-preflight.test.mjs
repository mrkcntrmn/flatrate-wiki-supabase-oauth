import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

async function text(path) {
  return readFile(new URL(`../${path}`, import.meta.url), "utf8");
}

test("ACCOUNT-DELETION-001C preflight is routed through the existing signed SSO bridge", async () => {
  const routes = await text("extend.php");
  const controller = await text("src/Sso/DeletionPreflightController.php");
  const provider = await text("src/ServiceProvider.php");

  assert.match(
    routes,
    /post\('\/flatrate-sso\/deletion\/preflight',\s*'flatrate-sso\.deletion\.preflight',\s*Sso\\DeletionPreflightController::class\)/,
  );
  assert.doesNotMatch(routes, /flatrate-sso\/deletion\/execute/);
  assert.match(provider, /'flatrate-sso\.deletion\.preflight'/);
  assert.match(controller, /SharedSecretAuthenticator \$authenticator/);
  assert.match(controller, /\$this->authenticator->authenticate\(\$request\)/);
  assert.match(controller, /Cache-Control' => 'no-store'/);
  assert.match(controller, /Referrer-Policy' => 'no-referrer'/);
});

test("preflight resolves Community identity only through flatrate provider + immutable sub", async () => {
  const source = await text("src/Sso/DeletionPreflight.php");

  assert.match(source, /table\('login_providers'\)/);
  assert.match(source, /where\('provider', 'flatrate'\)/);
  assert.match(source, /where\('identifier', \$sub\)/);
  assert.doesNotMatch(source, /where\('email'/);
  assert.doesNotMatch(source, /where\('username'/);
  assert.doesNotMatch(source, /tech_assignment/i);
});

test("preflight is fail-closed and protects Flarum root admin", async () => {
  const source = await text("src/Sso/DeletionPreflight.php");

  assert.match(source, /\$rootAdminBlocked = \$userId === 1/);
  assert.match(source, /community_deletion_identity_inconsistent/);
  assert.match(source, /community_deletion_preflight_unavailable/);
  assert.match(source, /'destructive_execute_supported' => false/);
});

test("preflight exposes bounded counts and policy only, never content or identity values", async () => {
  const source = await text("src/Sso/DeletionPreflight.php");

  for (const key of [
    "public_posts",
    "public_discussions",
    "access_tokens",
    "login_provider_links",
    "sso_tickets",
    "direct_messages_authored",
    "direct_conversation_memberships",
    "live_chat_messages_authored",
    "live_chat_memberships",
  ]) {
    assert.match(source, new RegExp(`'${key}'`));
  }

  assert.match(source, /'public_content_policy' => 'retain_detach_author'/);
  assert.match(source, /'private_direct_messages_policy' => 'delete_authored_private_content'/);
  assert.match(source, /'private_live_chat_policy' => 'delete_authored_private_content'/);
  assert.match(source, /'media_policy_state' => 'requires_deployed_extension_qualification'/);

  assert.doesNotMatch(source, /'email'\s*=>/);
  assert.doesNotMatch(source, /'username'\s*=>/);
  assert.doesNotMatch(source, /'nickname'\s*=>/);
  assert.doesNotMatch(source, /'message'\s*=>/);
  assert.doesNotMatch(source, /'content'\s*=>/);
  assert.doesNotMatch(source, /'ip_address'\s*=>/);
  assert.doesNotMatch(source, /'token'\s*=>/);
  assert.doesNotMatch(source, /'user_id'\s*=>/);
});

test("preflight performs no account/content/session/ticket mutation", async () => {
  const source = [
    await text("src/Sso/DeletionPreflight.php"),
    await text("src/Sso/DeletionPreflightController.php"),
  ].join("\n");

  assert.doesNotMatch(source, /->insert\s*\(/);
  assert.doesNotMatch(source, /->update\s*\(/);
  assert.doesNotMatch(source, /->delete\s*\(/);
  assert.doesNotMatch(source, /->save\s*\(/);
  assert.doesNotMatch(source, /DeleteUser/);
});

test("optional Direct and Live schemas are detected before counting", async () => {
  const source = await text("src/Sso/DeletionPreflight.php");

  assert.match(source, /hasColumn\(\$schema, 'messages', 'user_id'\)/);
  assert.match(source, /hasColumn\(\$schema, 'conversation_user', 'user_id'\)/);
  assert.match(source, /hasColumn\(\$schema, 'neonchat_messages', 'user_id'\)/);
  assert.match(source, /hasColumn\(\$schema, 'neonchat_chat_user', 'user_id'\)/);
});
