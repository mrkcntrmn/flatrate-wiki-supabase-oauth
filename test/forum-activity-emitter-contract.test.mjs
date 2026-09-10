import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, "..");

test("activity emitter sources keep feature gate and privacy exclusions", () => {
  const client = readFileSync(join(ROOT, "src/Activity/ActivityClient.php"), "utf8");
  const emitter = readFileSync(join(ROOT, "src/Activity/ActivityEmitter.php"), "utf8");
  const vote = readFileSync(join(ROOT, "src/Activity/EmitVoteActivity.php"), "utf8");
  const brand = readFileSync(join(ROOT, "src/Activity/BrandContext.php"), "utf8");
  const outbox = readFileSync(join(ROOT, "src/Activity/OutboxStore.php"), "utf8");
  const drainer = readFileSync(join(ROOT, "src/Activity/ActivityOutboxDrainer.php"), "utf8");
  const extend = readFileSync(join(ROOT, "extend.php"), "utf8");

  assert.match(client, /FLATRATE_ACTIVITY_EMIT_ENABLED/);
  assert.match(client, /DEFAULT_TIMEOUT_SECONDS = 2\.5/);
  assert.match(emitter, /resolveSub/);
  assert.match(emitter, /flatrate_activity_skipped_no_identity/);
  assert.match(emitter, /must never roll back/i);
  assert.match(vote, /vote_transition/);
  assert.match(vote, /VoteStateStore/);
  assert.match(brand, /position === null/);
  assert.match(brand, /ACCEPTED_BRAND_SLUGS/);
  assert.match(brand, /alpha-romeo/);
  assert.match(outbox, /claimDue/);
  assert.match(outbox, /terminal_at/);
  assert.match(outbox, /redacted/);
  assert.match(drainer, /postObservation/);
  assert.match(extend, /ActivityServiceProvider/);
  assert.match(extend, /DrainActivityOutboxCommand/);
  assert.match(extend, /everyMinute/);
  assert.match(extend, /flatrate-activity\.emit_enabled/);
  assert.equal(extend.includes("email_identity"), false);
});

test("R1 behavior PHP harness is wired", () => {
  const behavior = readFileSync(join(ROOT, "test/activity-r1-behavior.php"), "utf8");
  const ci = readFileSync(join(ROOT, ".github/workflows/ci.yml"), "utf8");
  assert.match(behavior, /ACTIVITY_R1_BEHAVIOR_PASS/);
  assert.match(behavior, /fresh nonce each attempt/);
  assert.match(behavior, /secondary warranty/);
  assert.match(ci, /activity-r1-behavior\.php/);
});

test("PHP HMAC fixture independently matches pinned vector", () => {
  const result = spawnSync("php", [join(ROOT, "test/forum-activity-hmac-vector.php")], {
    encoding: "utf8",
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(result.stdout, /PHP_HMAC_VECTOR_PASS=true/);
  assert.match(result.stdout, /CROSS_LANGUAGE_SIGNATURE_MATCH=true/);
});

test("SaveJobBreakdownMarker emits only after marker persistence path", () => {
  const marker = readFileSync(join(ROOT, "src/Markers/SaveJobBreakdownMarker.php"), "utf8");
  assert.match(marker, /setJobBreakdown/);
  assert.match(marker, /EmitJobBreakdownCreated/);
  assert.match(marker, /hadMarker/);
});
