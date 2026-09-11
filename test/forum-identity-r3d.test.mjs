import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("R3D: count()+1 allocator removed from live provisioner", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.doesNotMatch(provisioner, /\$linkedUsers\s*=\s*LoginProvider::where\('provider', 'flatrate'\)/);
  assert.doesNotMatch(provisioner, /\$userNumber\s*=\s*\$linkedUsers->count\(\)\s*\+\s*1/);
  assert.doesNotMatch(provisioner, /count\(\)\s*\+\s*1/);
  assert.match(provisioner, /TechNumber::parseOptional/);
  assert.match(provisioner, /tech_number_required/);
  assert.match(provisioner, /tech_number_nickname_collision/);
  assert.match(provisioner, /NeutralIdentity::nickname\(\$techNumber\)/);
  assert.doesNotMatch(provisioner, /ensure_forum_tech_assignment|SUPABASE_|createClient\(|curl_exec|file_get_contents\s*\(\s*['\"]https?:\/\//i);
});

test("R3D: linked-user check precedes tech_number requirement", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  const linkedIdx = provisioner.indexOf("if ($linked = $this->linkedUser($sub))");
  const emailIdx = provisioner.indexOf("existing_account_requires_explicit_link");
  const techIdx = provisioner.indexOf("TechNumber::parseOptional");
  const requiredIdx = provisioner.indexOf("tech_number_required");
  assert.ok(linkedIdx >= 0 && techIdx > linkedIdx, "parse tech_number after first linked check");
  assert.ok(emailIdx > linkedIdx && emailIdx < techIdx, "email collision before tech_number");
  assert.ok(requiredIdx > techIdx, "required error after parse");

  // Second linked check inside transaction must also precede tech parse.
  const txLinked = provisioner.indexOf("if ($linked = $this->linkedUser($sub))", linkedIdx + 1);
  assert.ok(txLinked > linkedIdx && txLinked < techIdx, "in-transaction linked check before tech_number");
});

test("R3D: TechNumber enforces MIN 20031 and strict JSON integers", async () => {
  const payload = await text("src/Identity/TechNumber.php");
  assert.match(payload, /MIN_TECH_NUMBER = 20031/);
  assert.match(payload, /MAX_SAFE_INTEGER = 9007199254740991/);
  assert.match(payload, /is_int\(\$value\)/);
  assert.match(payload, /tech_number_required/);
  assert.match(payload, /invalid_tech_number/);
  assert.doesNotMatch(payload, /preg_match/);
  // Explicit null is present-but-invalid (parseBoundedInteger), not missing.
  assert.match(
    payload,
    /if \(! array_key_exists\('tech_number', \$payload\)\) \{\s*return null;/s,
  );
  assert.doesNotMatch(payload, /\$payload\['tech_number'\] === null/);
});

test("R3D: existing linked path precedes any tech_number parse (null ignored)", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  const linkedIdx = provisioner.indexOf("if ($linked = $this->linkedUser($sub))");
  const techIdx = provisioner.indexOf("TechNumber::parseOptional");
  assert.ok(linkedIdx >= 0 && techIdx > linkedIdx);
  // First return after linked check must happen before tech parse.
  const firstReturn = provisioner.indexOf("return $this->reconcileLinkedEmail", linkedIdx);
  assert.ok(firstReturn > linkedIdx && firstReturn < techIdx);
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

test("R3D: cross-repo contract fixture agrees on field and codes", async () => {
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
