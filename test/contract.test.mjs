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

test("public routing handle is neutral and nickname is separately configurable", async () => {
  const provider = await text("src/Providers/FlatRate.php");
  const handleMethod = provider.slice(
    provider.indexOf("private function neutralHandle"),
    provider.indexOf("private function neutralNickname")
  );

  assert.match(provider, /->suggestUsername\(\$handle\)/);
  assert.match(provider, /->suggest\('nickname', \$this->neutralNickname\(\$sub\)\)/);
  assert.match(handleMethod, /'tech_'\.substr\(hash\('sha256', \$sub\), 0, 8\)/);
  assert.doesNotMatch(handleMethod, /email/i);
  assert.doesNotMatch(handleMethod, /preferred_username/i);
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

test("operator docs bind the confidential client to client_secret_post and S256", async () => {
  const readme = await text("README.md");

  assert.match(readme, /client_secret_post/);
  assert.match(readme, /code_challenge_method=S256/);
  assert.match(readme, /exact callback shown by the live provider settings/i);
  assert.match(readme, /email is never used to infer or auto-link/i);
});
