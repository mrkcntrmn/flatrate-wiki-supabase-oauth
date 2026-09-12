import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

const LEGACY = /^tech_[0-9]+$/i;
const CANONICAL = /^tech_#[0-9]+$/i;

function matchesLegacy(nickname) {
  if (typeof nickname !== "string") return false;
  const value = nickname.trim();
  return value !== "" && LEGACY.test(value);
}

function matchesCanonical(nickname) {
  if (typeof nickname !== "string") return false;
  const value = nickname.trim();
  return value !== "" && CANONICAL.test(value);
}

function matches(nickname) {
  return matchesLegacy(nickname) || matchesCanonical(nickname);
}

test("reserved namespaces keep tech_307 distinct from tech_#307", () => {
  assert.equal(matchesLegacy("tech_307"), true);
  assert.equal(matchesCanonical("tech_307"), false);
  assert.equal(matchesLegacy("tech_#307"), false);
  assert.equal(matchesCanonical("tech_#307"), true);
  assert.notEqual("tech_307", "tech_#307");
});

test("legacy and canonical reserved forms are case-insensitive", () => {
  for (const value of ["tech_307", "Tech_307", "TECH_307", "tech_#307", "Tech_#307", "TECH_#307"]) {
    assert.equal(matches(value), true, value);
  }
  assert.equal(matches("DieselDave"), false);
  assert.equal(matches("tech_master"), false);
  assert.equal(matches("tech_"), false);
  assert.equal(matches("tech_#"), false);
  assert.equal(matches("tech_12a"), false);
});

test("RejectReservedTechNickname inspects attributes.nickname only", async () => {
  const listener = await text("src/Listeners/RejectReservedTechNickname.php");
  const reserved = await text("src/Identity/ReservedTechNickname.php");
  const extend = await text("extend.php");
  const locale = await text("resources/locale/en.yml");

  assert.match(reserved, /LEGACY_PATTERN = '\/\^tech_\[0-9\]\+\$\/i'/);
  assert.match(reserved, /CANONICAL_PATTERN = '\/\^tech_#\[0-9\]\+\$\/i'/);
  assert.match(listener, /array_key_exists\('nickname', \$attributes\)/);
  assert.match(listener, /ReservedTechNickname::matches/);
  assert.match(listener, /ValidationException/);
  assert.match(listener, /flatrate-identity\.api\.reserved_tech_nickname/);
  assert.doesNotMatch(listener, /\$event->user->nickname/);

  assert.match(extend, /UserSaving::class, Listeners\\RejectReservedTechNickname::class/);
  assert.match(extend, /use Flarum\\User\\Event\\Saving as UserSaving/);
  assert.match(extend, /Saving::class, Markers\\SaveJobBreakdownMarker::class/);

  assert.match(locale, /reserved_tech_nickname:/);
});

test("token registration keeps nickname out of request attributes", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /'nickname'\s*=>\s*\$temporaryNickname/);
  assert.match(
    provisioner,
    /'attributes'\s*=>\s*\[[^\]]*'username'\s*=>\s*\$username[^\]]*'email'\s*=>\s*\$email[^\]]*'token'\s*=>\s*\$token->token/s,
  );
  assert.doesNotMatch(
    provisioner,
    /'attributes'\s*=>\s*\[[^\]]*'nickname'\s*=>/s,
  );
});

test("count()+1 remains removed from the provisioner", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.doesNotMatch(provisioner, /\$userNumber = \$linkedUsers->count\(\) \+ 1/);
  assert.doesNotMatch(provisioner, /TechNumber::parseOptional/);
  const extend = await text("extend.php");
  assert.match(extend, /RejectReservedTechNickname::class/);
});
