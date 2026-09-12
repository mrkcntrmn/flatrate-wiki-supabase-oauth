import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("R3D: count()+1 allocator remains removed from live provisioner", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.doesNotMatch(provisioner, /\$linkedUsers\s*=\s*LoginProvider::where\('provider', 'flatrate'\)/);
  assert.doesNotMatch(provisioner, /\$userNumber\s*=\s*\$linkedUsers->count\(\)\s*\+\s*1/);
  assert.doesNotMatch(provisioner, /count\(\)\s*\+\s*1/);
  assert.doesNotMatch(provisioner, /max\s*\(\s*\$/);
  assert.doesNotMatch(provisioner, /ensure_forum_tech_assignment|SUPABASE_|createClient\(|curl_exec|file_get_contents\s*\(\s*['\"]https?:\/\//i);
});

test("IDENTITY-002: new users do not require the 20031+ tech_number allocator", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.doesNotMatch(provisioner, /TechNumber::parseOptional/);
  assert.doesNotMatch(provisioner, /TechNumber::parseRequired/);
  assert.doesNotMatch(provisioner, /tech_number_required/);
  assert.doesNotMatch(provisioner, /NeutralIdentity::nickname\(\$techNumber\)/);
  assert.match(provisioner, /MemberIdentity::nickname/);
  assert.match(provisioner, /createForNewUser/);
  assert.match(provisioner, /unset\(\$payload\['tech_number'\]\)/);
});

test("R3D: TechNumber remains legacy rollback contract only", async () => {
  const payload = await text("src/Identity/TechNumber.php");
  assert.match(payload, /MIN_TECH_NUMBER = 20031/);
  assert.match(payload, /MAX_SAFE_INTEGER = 9007199254740991/);
  assert.match(payload, /is_int\(\$value\)/);
  assert.match(payload, /tech_number_required/);
  assert.match(payload, /invalid_tech_number/);
  assert.doesNotMatch(payload, /preg_match/);
  assert.match(
    payload,
    /if \(! array_key_exists\('tech_number', \$payload\)\) \{\s*return null;/s,
  );
  assert.doesNotMatch(payload, /\$payload\['tech_number'\] === null/);
});

test("R3D: existing linked path precedes registration", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  const linkedIdx = provisioner.indexOf("if ($linked = $this->linkedUser($sub))");
  const emailIdx = provisioner.indexOf("existing_account_requires_explicit_link");
  const registerIdx = provisioner.indexOf("RegisterUser(");
  assert.ok(linkedIdx >= 0 && registerIdx > linkedIdx);
  assert.ok(emailIdx > linkedIdx && emailIdx < registerIdx);
  const firstReturn = provisioner.indexOf("return $this->finishLinkedUser", linkedIdx);
  assert.ok(firstReturn > linkedIdx && firstReturn < registerIdx);
});

test("R3D: controllers pass HMAC body through to provisioner", async () => {
  const ticket = await text("src/Sso/TicketController.php");
  const provision = await text("src/Sso/ProvisionController.php");
  assert.match(ticket, /authenticate\(\$request\)/);
  assert.match(provision, /authenticate\(\$request\)/);
  assert.match(ticket, /\$this->provisioner->ensure\(\$sub, \$email, \$verified, \$body\)/);
  assert.match(provision, /\$this->provisioner->ensure\(\$sub, \$email, \$verified, \$body\)/);
  assert.match(ticket, /SsoException/);
  assert.match(provision, /SsoException/);
});

test("R3D: reservation guard remains registered", async () => {
  const extend = await text("extend.php");
  assert.match(extend, /RejectReservedTechNickname::class/);
});

test("R3D: cross-repo contract fixture still documents legacy 409 codes", async () => {
  const fixture = JSON.parse(await text("test/fixtures/tech-number-contract.json"));
  assert.equal(fixture.field, "tech_number");
  assert.equal(fixture.valid_example, 20031);
  assert.equal(fixture.min, 20031);
  assert.equal(fixture.max_safe_integer, 9007199254740991);
  assert.equal(fixture.missing.status, 409);
  assert.equal(fixture.missing.error, "tech_number_required");
  assert.equal(fixture.invalid.status, 400);
  assert.equal(fixture.invalid.error, "invalid_tech_number");
  assert.equal(fixture.collision.error, "tech_number_nickname_collision");
  assert.deepEqual(fixture.invalid_examples, [20030, 0, -1, 1.5, false, "20031", "abc", "tech_20031", null]);
});
