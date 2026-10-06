import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const root = new URL("../", import.meta.url);
const text = (file) => readFile(new URL(file, root), "utf8");

async function walk(dir, hits) {
  const entries = await readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      await walk(full, hits);
      continue;
    }
    const source = await readFile(full, "utf8");
    if (/beta_tester_active|betaTesterActive|flatrate_beta_tester_access/.test(source)) {
      hits.push(path.relative(new URL(".", root).pathname, full));
    }
  }
}

test("001B migration is a private projection table", async () => {
  const migration = await text("migrations/2026_10_06_000000_create_beta_tester_projection.php");
  assert.match(migration, /flatrate_beta_tester_access/);
  assert.match(migration, /unsignedInteger\('user_id'\)/);
  assert.match(migration, /primary\('user_id'\)/);
  assert.match(migration, /boolean\('active'\)->default\(false\)/);
  assert.match(migration, /timestamp\('synced_at'\)/);
  assert.match(migration, /on\('users'\)/);
  assert.match(migration, /onDelete\('cascade'\)/);
  assert.match(migration, /dropIfExists\('flatrate_beta_tester_access'\)/);
  for (const leaked of ["email", "phone", "access_token", "refresh_token", "username", "notes"]) {
    assert.equal(migration.includes(`'${leaked}'`), false, leaked);
  }
});

test("001B projection store fail-closes and reconciles through the user id", async () => {
  const store = await text("src/Beta/BetaTesterProjectionStore.php");
  const table = await text("src/Beta/BetaTesterProjectionTable.php");
  const contract = await text("src/Beta/BetaTesterProjection.php");
  assert.match(store, /function isActive\(User \$user\): bool/);
  assert.match(store, /function sync\(User \$user, bool \$active\): bool/);
  assert.match(store, /if \(\$id < 1\)/);
  assert.match(contract, /function isActive\(User \$actor\): bool/);
  assert.match(table, /missing row|return false/);
  assert.match(table, /previous !== \$active/);
  assert.doesNotMatch(store, /setPreference|getPreference|->preferences\(/);
  assert.doesNotMatch(table, /setPreference|getPreference|->preferences\(/);
});

test("001B SSO reconciles only an explicit boolean after user resolution", async () => {
  const provision = await text("src/Sso/ProvisionController.php");
  const ticket = await text("src/Sso/TicketController.php");
  const sync = await text("src/Sso/BetaAccessSyncController.php");
  const parser = await text("src/Sso/BetaTesterPayload.php");
  for (const source of [provision, ticket]) {
    const ensureAt = source.indexOf("provisioner->ensure(");
    const betaAt = source.indexOf("BetaTesterPayload::optional(");
    const syncAt = source.indexOf("projection->sync(");
    assert.ok(ensureAt > 0 && betaAt > ensureAt && syncAt > betaAt);
    assert.match(source, /if \(\$betaActive !== null\)/);
    assert.doesNotMatch(source, /FILTER_VALIDATE_BOOLEAN[\s\S]{0,80}beta_tester_active/);
    assert.doesNotMatch(source, /setPreference|getPreference|->preferences\(/);
  }
  const issueAt = ticket.indexOf("tickets->issue(");
  const ticketSyncAt = ticket.indexOf("projection->sync(");
  assert.ok(ticketSyncAt > 0 && issueAt > ticketSyncAt);
  assert.match(parser, /is_bool\(\$value\)/);
  assert.match(parser, /invalid_beta_tester_active/);
  assert.doesNotMatch(parser, /FILTER_VALIDATE_BOOLEAN/);
  assert.match(sync, /authenticator->authenticate\(\$request\)/);
  assert.match(sync, /BetaTesterPayload::required\(\$body\)/);
  assert.match(sync, /linkedUsers->findBySubject\(\$sub\)/);
  assert.match(sync, /'linked' => false/);
  assert.match(sync, /'changed' => false/);
  assert.doesNotMatch(sync, /provisioner->ensure\(|RegistrationToken|LoginProvider::/);
  const authAt = sync.indexOf("authenticator->authenticate(");
  const requiredAt = sync.indexOf("BetaTesterPayload::required(");
  const findAt = sync.indexOf("linkedUsers->findBySubject(");
  const syncAt = sync.indexOf("projection->sync(");
  assert.ok(authAt >= 0 && requiredAt > authAt && findAt > requiredAt && syncAt > findAt);
});

test("001B beta-access route is HMAC-only and container-resolvable", async () => {
  const extension = await text("extend.php");
  const provider = await text("src/ServiceProvider.php");
  assert.match(extension, /\/flatrate-sso\/beta-access', 'flatrate-sso\.beta-access', Sso\\BetaAccessSyncController::class/);
  assert.match(provider, /flatrate-sso\.beta-access/);
  assert.match(provider, /singleton\(BetaTesterProjectionStore::class\)/);
  assert.match(provider, /alias\(BetaTesterProjectionStore::class, BetaTesterProjection::class\)/);
});

test("001B linked lookup does not create users", async () => {
  const resolver = await text("src/Beta/LinkedFlatRateUserResolver.php");
  assert.match(resolver, /provider', 'flatrate'/);
  assert.match(resolver, /identifier', \$sub/);
  assert.doesNotMatch(resolver, /save\(|RegistrationToken|RegisterUser|new LoginProvider/);
});

test("001B browser surfaces do not expose the raw projection", async () => {
  const rootDir = new URL(".", root).pathname;
  const hits = [];
  for (const dir of ["js", "resources", "src/Api"]) {
    await walk(path.join(rootDir, dir), hits);
  }
  assert.deepEqual(hits, []);
});

test("001B leaves the production composer constraint unchanged", async () => {
  const composer = await text("composer.json");
  assert.match(composer, /"flarum\/core": "\^1\.8\.1"/);
});
