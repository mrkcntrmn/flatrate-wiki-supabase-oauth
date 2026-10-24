import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);

async function text(path) {
  return readFile(new URL(path, root), "utf8");
}

test("F2 canary: composer requires exact Flarum 2.0.2 and not the F1 extension set", async () => {
  const composer = JSON.parse(await text("composer.json"));
  assert.equal(composer.require["flarum/core"], "2.0.2");
  assert.equal(composer.require["fof/oauth"], undefined);
  assert.equal(composer.require["fof/extend"], undefined);
  assert.equal(composer.require["flarum/nicknames"], undefined);
  assert.equal(composer.require["flarum/tags"], undefined);
  assert.match(composer.description, /not a complete port/i);
  assert.doesNotMatch(composer.require["flarum/core"], /\*/);
});

test("F2 canary: extend.php does not boot excluded F1 surfaces", async () => {
  const extend = await text("extend.php");
  const absent = [
    "ApiSerializer",
    "RegisterUserHandler",
    "FoF\\OAuth",
    "AdminGamify",
    "MemberDisplayController",
    "beta-access",
    "flatrate-voting",
    "DrainActivityOutboxController",
  ];
  for (const token of absent) {
    assert.equal(extend.includes(token), false, token);
  }
  assert.match(extend, /RequireFlatRateIdentity/);
  assert.match(extend, /flatrate-sso\/provision/);
  assert.match(extend, /flatrate-sso\/ticket/);
  assert.match(extend, /auth\/flatrate\/session/);
});

test("F2 canary: active migrations are the SSO bridge and legacy counter defaults", async () => {
  const active = (await readdir(new URL("migrations/", root))).filter((name) => name.endsWith(".php")).sort();
  assert.deepEqual(active, [
    "2026_08_28_000000_create_sso_bridge_tables.php",
    "2026_10_24_000000_default_legacy_counter_columns.php",
  ]);
});

test("F2 canary: historical migrations remain source-accessible and inactive", async () => {
  const historical = (await readdir(new URL("migrations-unqualified/", root))).filter((name) => name.endsWith(".php")).sort();
  assert.deepEqual(historical, [
    "2026_08_27_000000_enable_public_nicknames.php",
    "2026_08_30_000000_create_post_markers.php",
    "2026_09_01_000000_widen_masquerade_field_validation.php",
    "2026_09_10_000000_create_activity_emitter_tables.php",
    "2026_09_10_120000_activity_outbox_terminal_at.php",
    "2026_09_12_000000_create_flatrate_member_profiles.php",
    "2026_09_16_000000_repair_activity_table_prefix.php",
    "2026_10_06_000000_create_beta_tester_projection.php",
  ]);
  const extend = await text("extend.php");
  assert.equal(extend.includes("migrations-unqualified"), false);
});

test("F2 canary: registration sends the generated handle and ignores a request username", async () => {
  const provisioner = await text("src/Canary/IdentityProvisioner.php");
  const controller = await text("src/Canary/ProvisionController.php");
  assert.match(provisioner, /NeutralIdentity::handle\(\$sub\)/);
  assert.match(provisioner, /'username'\s*=>\s*\$username/);
  assert.equal(controller.includes("body['username']"), false);
  assert.equal(controller.includes('body["username"]'), false);
});

test("F2 canary: package text does not claim a complete production replacement", async () => {
  const readme = await text("CANARY.md");
  assert.match(readme, /not a port of the Flarum 1 extension/i);
  assert.match(readme, /not a production release/i);
});
