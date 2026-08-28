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

test("provider requires S256 and immutable sub identity", async () => {
  const provider = await text("src/Providers/FlatRate.php");

  assert.match(provider, /PKCE_METHOD_S256/);
  assert.match(provider, /'responseResourceOwnerId'\s*=>\s*'sub'/);
  assert.match(provider, /\/auth\/v1\/oauth\/authorize/);
  assert.match(provider, /\/auth\/v1\/oauth\/token/);
  assert.match(provider, /\/auth\/v1\/oauth\/userinfo/);
  assert.match(provider, /'openid',\s*'email',\s*'profile'/);
  assert.doesNotMatch(provider, /provideTrustedEmail/);
  assert.match(provider, /suggestEmail/);
});

test("public routing handle and neutral nickname derive only from immutable sub", async () => {
  const provider = await text("src/Providers/FlatRate.php");
  const identity = await text("src/Identity/NeutralIdentity.php");

  assert.match(provider, /NeutralIdentity::handle\(\$sub\)/);
  assert.match(provider, /NeutralIdentity::nickname\(\$sub\)/);
  assert.match(identity, /'tech_'\.substr\(hash\('sha256', \$sub\), 0, 8\)/);
  assert.match(identity, /'Tech '\.strtoupper\(substr\(hash\('sha256', \$sub\), 0, 4\)\)/);
  assert.doesNotMatch(identity, /email/i);
  assert.doesNotMatch(identity, /preferred_username/i);
});

test("FlatRate OAuth silently provisions only verified new identities", async () => {
  const factory = await text("src/Auth/AutoProvisioningResponseFactory.php");
  const serviceProvider = await text("src/ServiceProvider.php");
  const extension = await text("extend.php");

  assert.match(factory, /extends ResponseFactory/);
  assert.match(factory, /\$provider !== 'flatrate'/);
  assert.match(factory, /LoginProvider::logIn\(\$provider, \$identifier\)/);
  assert.match(factory, /email_verified/);
  assert.match(factory, /hash_equals\(\$identifier, \$sub\)/);
  assert.match(factory, /User::where\('email', \$email\)->exists\(\)/);
  assert.match(factory, /existing_account_requires_explicit_link/);
  assert.match(factory, /RegistrationToken::generate/);
  assert.match(factory, /RegisterUserHandler/);
  assert.match(factory, /new Guest\(\)/);
  assert.match(factory, /->transaction\(/);
  assert.match(factory, /->makeLoggedInResponse\(\$user\)/);
  assert.match(factory, /NeutralIdentity::handle\(\$sub\)/);
  assert.match(factory, /NeutralIdentity::nickname\(\$sub\)/);

  assert.match(serviceProvider, /bind\(ResponseFactory::class, AutoProvisioningResponseFactory::class\)/);
  assert.match(extension, /Extend\\ServiceProvider/);
  assert.match(extension, /register\(ServiceProvider::class\)/);
});

test("FlatRate OAuth success supports popup login and top-level Community Settings handoff", async () => {
  const factory = await text("src/Auth/AutoProvisioningResponseFactory.php");

  assert.match(factory, /private bool \$flatRateFlow = false/);
  assert.match(factory, /return parent::makeResponse\(\$payload\)/);
  assert.match(factory, /window\.opener&&window\.opener\.app/);
  assert.match(factory, /authenticationComplete/);
  assert.match(factory, /window\.close\(\)/);
  assert.match(factory, /window\.location\.replace\('\/settings'\)/);
  assert.match(factory, /finally\s*\{\s*\$this->flatRateFlow = false;/);
});

test("nickname migration selects nickname display and grants self-edit", async () => {
  const migration = await text("migrations/2026_08_27_000000_enable_public_nicknames.php");

  assert.match(migration, /'display_name_driver'\s*=>\s*'nickname'/);
  assert.match(migration, /'flarum-nicknames\.set_on_registration'\s*=>\s*'1'/);
  assert.match(migration, /'flarum-nicknames\.random_username'\s*=>\s*'0'/);
  assert.match(migration, /user\.editOwnNickname/);
  assert.match(migration, /Group::MEMBER_ID/);
  assert.match(migration, /login_providers/);
  assert.match(migration, /where\('provider', 'flatrate'\)/);
  assert.match(migration, /'tech_'\.\$suffix/);
});

test("verified Supabase email activates only the matching OAuth registration", async () => {
  const listener = await text("src/Listeners/TrustVerifiedSupabaseEmail.php");

  assert.match(listener, /provider !== 'flatrate'/);
  assert.match(listener, /email_verified/);
  assert.match(listener, /strcasecmp\(\$providerEmail, \$userEmail\)/);
  assert.match(listener, /->activate\(\)/);
});

test("ordinary native password login and password signup are blocked server-side", async () => {
  const middleware = await text("src/Middleware/RequireFlatRateIdentity.php");

  assert.match(middleware, /\/login/);
  assert.match(middleware, /\/api\/token/);
  assert.match(middleware, /\/api\/users/);
  assert.match(middleware, /isAdmin\(\)/);
  assert.match(middleware, /attributes'\]\['token'/);
  assert.match(middleware, /flatrate_sso_required/);
});

test("forum UI exposes one branded SSO CTA and removes local credential controls", async () => {
  const provider = await text("src/Providers/FlatRate.php");
  const css = await text("resources/less/forum.less");
  const locale = await text("resources/locale/en.yml");

  assert.match(provider, /fas fa-sign-in-alt/);
  assert.match(css, /\.LogInModal[\s\S]*\.Form/);
  assert.match(css, /\.Modal-footer/);
  assert.match(css, /LogInButtonContainer--flatrate/);
  assert.match(css, /max-width:\s*420px/);
  assert.match(css, /min-height:\s*54px/);
  assert.match(css, /\.Button-labelText[\s\S]*text-overflow:\s*clip/);
  assert.match(css, /@media \(max-width: 767px\)/);
  assert.match(css, /\.item-signUp/);
  assert.match(css, /\.item-changePassword/);
  assert.match(css, /\.item-changeEmail/);
  assert.match(locale, /Continue with FlatRate\.wiki/);
});

test("operator docs bind the confidential client to S256 and explicit existing-account linking", async () => {
  const readme = await text("README.md");

  assert.match(readme, /client_secret_post/);
  assert.match(readme, /code_challenge_method=S256/);
  assert.match(readme, /exact callback shown by the live provider settings/i);
  assert.match(readme, /email is never used to infer or auto-link/i);
  assert.match(readme, /silently provisions/i);
  assert.match(readme, /existing_account_requires_explicit_link/);
  assert.match(readme, /linkTo=<FLARUM_USER_ID>/);
  assert.match(readme, /no second Flarum Sign Up confirmation/i);
});
