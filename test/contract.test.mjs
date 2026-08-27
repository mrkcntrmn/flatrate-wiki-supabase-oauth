import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);

async function text(path) {
  return readFile(new URL(path, packageDir), "utf8");
}

test("package pins the PKCE-capable Flarum/FoF floor", async () => {
  const composer = JSON.parse(await text("composer.json"));

  assert.equal(composer.name, "flatrate/wiki-supabase-oauth");
  assert.equal(composer.require["flarum/core"], "^1.8.1");
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

test("public username suggestion never falls back to email or display name", async () => {
  const provider = await text("src/Providers/FlatRate.php");
  const usernameMethod = provider.slice(provider.indexOf("private function usernameSuggestion"));

  assert.match(usernameMethod, /preferred_username/);
  assert.match(usernameMethod, /candidate = 'tech'/);
  assert.doesNotMatch(usernameMethod, /payload\['email'\]/);
  assert.doesNotMatch(usernameMethod, /payload\['name'\]/);
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

test("forum login UI exposes one readable branded FlatRate.wiki CTA", async () => {
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
  assert.match(locale, /Continue with FlatRate\.wiki/);
});

test("operator docs bind the confidential client to client_secret_post and S256", async () => {
  const readme = await text("README.md");

  assert.match(readme, /client_secret_post/);
  assert.match(readme, /code_challenge_method=S256/);
  assert.match(readme, /exact callback shown by the live provider settings/i);
  assert.match(readme, /email is never used to infer or auto-link/i);
});
