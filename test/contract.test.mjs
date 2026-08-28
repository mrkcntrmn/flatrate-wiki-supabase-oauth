import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);

async function text(path) {
  return readFile(new URL(path, packageDir), "utf8");
}

test("package pins the PKCE-capable Flarum/FoF floor and Nicknames", async () => {
  const composer = JSON.parse(await text("composer.json"));
  assert.equal(composer.name, "flatrate/wiki-supabase-oauth");
  assert.equal(composer.require["flarum/core"], "^1.8.1");
  assert.equal(composer.require["flarum/nicknames"], "^1.8.3");
  assert.equal(composer.require["fof/oauth"], "^1.7.4");
  assert.equal(composer.require["fof/extend"], "^1.3.4");
  assert.equal(composer.require["league/oauth2-client"], "^2.7");
});

test("provider keeps PKCE S256 and immutable Supabase sub identity", async () => {
  const provider = await text("src/Providers/FlatRate.php");
  assert.match(provider, /PKCE_METHOD_S256/);
  assert.match(provider, /'responseResourceOwnerId'\s*=>\s*'sub'/);
  assert.match(provider, /\/auth\/v1\/oauth\/authorize/);
  assert.match(provider, /\/auth\/v1\/oauth\/token/);
  assert.match(provider, /\/auth\/v1\/oauth\/userinfo/);
  assert.match(provider, /'openid',\s*'email',\s*'profile'/);
  assert.match(provider, /suggestEmail/);
  assert.doesNotMatch(provider, /provideTrustedEmail/);
});

test("public routing identity derives only from immutable sub", async () => {
  const identity = await text("src/Identity/NeutralIdentity.php");
  assert.match(identity, /'tech_'\.substr\(hash\('sha256', \$sub\), 0, 8\)/);
  assert.match(identity, /'Tech '\.strtoupper\(substr\(hash\('sha256', \$sub\), 0, 4\)\)/);
  assert.doesNotMatch(identity, /email/i);
});

test("reusable provisioner is idempotent and never links by email", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /LoginProvider::where\('provider', 'flatrate'\)/);
  assert.match(provisioner, /where\('identifier', \$sub\)/);
  assert.match(provisioner, /User::where\('email', \$email\)->exists\(\)/);
  assert.match(provisioner, /existing_account_requires_explicit_link/);
  assert.match(provisioner, /RegistrationToken::generate/);
  assert.match(provisioner, /RegisterUserHandler/);
  assert.match(provisioner, /new Guest\(\)/);
  assert.match(provisioner, /->transaction\(/);
  assert.match(provisioner, /catch \(QueryException/);
  assert.match(provisioner, /NeutralIdentity::handle\(\$sub\)/);
  assert.match(provisioner, /NeutralIdentity::nickname\(\$sub\)/);
});

test("OAuth fallback delegates new-user creation to the reusable provisioner", async () => {
  const factory = await text("src/Auth/AutoProvisioningResponseFactory.php");
  const serviceProvider = await text("src/ServiceProvider.php");
  assert.match(factory, /private FlatRateUserProvisioner \$provisioner/);
  assert.match(factory, /LoginProvider::logIn\(\$provider, \$identifier\)/);
  assert.match(factory, /hash_equals\(\$identifier, \$sub\)/);
  assert.match(factory, /\$this->provisioner->ensure\(/);
  assert.match(factory, /makeLoggedInResponse\(\$user\)/);
  assert.match(serviceProvider, /bind\(ResponseFactory::class, AutoProvisioningResponseFactory::class\)/);
});

test("trusted bridge exposes provision, ticket, and session routes", async () => {
  const extension = await text("extend.php");
  assert.match(extension, /\/flatrate-sso\/provision/);
  assert.match(extension, /\/flatrate-sso\/ticket/);
  assert.match(extension, /\/auth\/flatrate\/session/);
  assert.match(extension, /Sso\\ProvisionController::class/);
  assert.match(extension, /Sso\\TicketController::class/);
  assert.match(extension, /Sso\\SessionController::class/);
});

test("internal requests require fresh HMAC plus replay-resistant nonce", async () => {
  const auth = await text("src/Sso/SharedSecretAuthenticator.php");
  assert.match(auth, /FORUM_SSO_SHARED_SECRET/);
  assert.match(auth, /X-FlatRate-Timestamp/);
  assert.match(auth, /X-FlatRate-Nonce/);
  assert.match(auth, /X-FlatRate-Signature/);
  assert.match(auth, /MAX_CLOCK_SKEW_SECONDS = 60/);
  assert.match(auth, /hash_hmac\('sha256'/);
  assert.match(auth, /hash_equals/);
  assert.match(auth, /flatrate_sso_nonces/);
  assert.match(auth, /replayed_sso_request/);
});

test("forum entry tickets are opaque, short-lived, and consumed atomically", async () => {
  const store = await text("src/Sso/TicketStore.php");
  const session = await text("src/Sso/SessionController.php");
  const migration = await text("migrations/2026_08_28_000000_create_sso_bridge_tables.php");
  assert.match(store, /TTL_SECONDS = 45/);
  assert.match(store, /random_bytes\(32\)/);
  assert.match(store, /hash\('sha256', \$ticket\)/);
  assert.match(store, /lockForUpdate\(\)/);
  assert.match(store, /whereNull\('consumed_at'\)/);
  assert.match(store, /invalid_return_to/);
  assert.match(session, /RememberAccessToken::generate/);
  assert.match(session, /\$this->rememberer->remember/);
  assert.match(session, /Cache-Control/);
  assert.match(session, /Referrer-Policy/);
  assert.match(migration, /flatrate_sso_tickets/);
  assert.match(migration, /ticket_hash/);
  assert.match(migration, /consumed_at/);
  assert.match(migration, /flatrate_sso_nonces/);
});

test("browser OAuth fallback still supports popup and top-level completion", async () => {
  const factory = await text("src/Auth/AutoProvisioningResponseFactory.php");
  assert.match(factory, /private bool \$flatRateFlow = false/);
  assert.match(factory, /return parent::makeResponse\(\$payload\)/);
  assert.match(factory, /window\.opener&&window\.opener\.app/);
  assert.match(factory, /authenticationComplete/);
  assert.match(factory, /window\.close\(\)/);
  assert.match(factory, /window\.location\.replace\('\/settings'\)/);
});

test("nickname migration selects nickname display and grants self-edit", async () => {
  const migration = await text("migrations/2026_08_27_000000_enable_public_nicknames.php");
  assert.match(migration, /'display_name_driver'\s*=>\s*'nickname'/);
  assert.match(migration, /user\.editOwnNickname/);
  assert.match(migration, /where\('provider', 'flatrate'\)/);
});

test("verified Supabase email activates only matching provider registration", async () => {
  const listener = await text("src/Listeners/TrustVerifiedSupabaseEmail.php");
  assert.match(listener, /provider !== 'flatrate'/);
  assert.match(listener, /email_verified/);
  assert.match(listener, /strcasecmp\(\$providerEmail, \$userEmail\)/);
  assert.match(listener, /->activate\(\)/);
});

test("ordinary native password login and signup remain blocked server-side", async () => {
  const middleware = await text("src/Middleware/RequireFlatRateIdentity.php");
  assert.match(middleware, /\/login/);
  assert.match(middleware, /\/api\/token/);
  assert.match(middleware, /\/api\/users/);
  assert.match(middleware, /isAdmin\(\)/);
  assert.match(middleware, /flatrate_sso_required/);
});

test("forum UI keeps branded fallback SSO and removes local credential controls", async () => {
  const css = await text("resources/less/forum.less");
  const locale = await text("resources/locale/en.yml");
  assert.match(css, /LogInButtonContainer--flatrate/);
  assert.match(css, /\.item-changePassword/);
  assert.match(css, /\.item-changeEmail/);
  assert.match(locale, /Continue with FlatRate\.wiki/);
});

test("operator docs describe seamless ticket bridge and OAuth rollback path", async () => {
  const readme = await text("README.md");
  assert.match(readme, /FORUM_SSO_SHARED_SECRET/);
  assert.match(readme, /one-time/i);
  assert.match(readme, /session bootstrap/i);
  assert.match(readme, /OAuth.*fallback|fallback.*OAuth/is);
  assert.match(readme, /existing_account_requires_explicit_link/);
});
