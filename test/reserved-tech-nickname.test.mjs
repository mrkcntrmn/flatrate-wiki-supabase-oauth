import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

/** Mirror of ReservedTechNickname::PATTERN for contract assertions. */
const RESERVED = /^tech_[0-9]+$/i;

function matches(nickname) {
  if (typeof nickname !== "string") return false;
  const value = nickname.trim();
  return value !== "" && RESERVED.test(value);
}

test("R3-A: reserved numeric namespace matches only tech_<digits>", () => {
  assert.equal(matches("tech_999"), true);
  assert.equal(matches("TECH_999"), true);
  assert.equal(matches("Tech_999"), true);
  assert.equal(matches("tech_000999"), true);
  assert.equal(matches("tech_20031"), true);
  assert.equal(matches("tech_0"), true);

  assert.equal(matches("DieselDan"), false);
  assert.equal(matches("tech_master"), false);
  assert.equal(matches("tech_diesel"), false);
  assert.equal(matches("technician_307"), false);
  assert.equal(matches("tech_"), false);
  assert.equal(matches("tech_12a"), false);
  assert.equal(matches(" tech_999"), true); // trim
  assert.equal(matches(""), false);
  assert.equal(matches(null), false);
});

test("R3-A: RejectReservedTechNickname inspects attributes.nickname only", async () => {
  const listener = await text("src/Listeners/RejectReservedTechNickname.php");
  const reserved = await text("src/Identity/ReservedTechNickname.php");
  const extend = await text("extend.php");
  const locale = await text("resources/locale/en.yml");

  assert.match(reserved, /PATTERN = '\/\^tech_\[0-9\]\+\$\/i'/);
  assert.match(listener, /array_key_exists\('nickname', \$attributes\)/);
  assert.match(listener, /ReservedTechNickname::matches/);
  assert.match(listener, /ValidationException/);
  assert.match(listener, /flatrate-identity\.api\.reserved_tech_nickname/);
  assert.doesNotMatch(listener, /\$event->user->nickname/);

  assert.match(extend, /UserSaving::class, Listeners\\RejectReservedTechNickname::class/);
  assert.match(extend, /use Flarum\\User\\Event\\Saving as UserSaving/);
  // Post Saving marker registration must remain distinct.
  assert.match(extend, /Saving::class, Markers\\SaveJobBreakdownMarker::class/);

  assert.match(locale, /reserved_tech_nickname:\s*That nickname is reserved for FlatRate technician IDs\./);
});

test("R3-A: token registration keeps nickname out of request attributes", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  // RegistrationToken carries nickname; RegisterUser request attributes do not.
  assert.match(provisioner, /'nickname'\s*=>\s*\$nickname/);
  assert.match(
    provisioner,
    /'attributes'\s*=>\s*\[[^\]]*'username'\s*=>\s*\$username[^\]]*'email'\s*=>\s*\$email[^\]]*'token'\s*=>\s*\$token->token/s,
  );
  assert.doesNotMatch(
    provisioner,
    /'attributes'\s*=>\s*\[[^\]]*'nickname'\s*=>/s,
  );
});

test("R3-A: count()+1 still present until later identity patch", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /\$userNumber = \$linkedUsers->count\(\) \+ 1/);
});

test("R3-A: TechNumberPayload remains unwired / parked outside reservation deploy", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.doesNotMatch(provisioner, /TechNumberPayload/);
  const extend = await text("extend.php");
  assert.doesNotMatch(extend, /TechNumberPayload/);
});
