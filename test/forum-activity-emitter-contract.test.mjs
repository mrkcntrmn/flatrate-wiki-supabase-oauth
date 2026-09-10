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
  const command = readFileSync(join(ROOT, "src/Activity/DrainActivityOutboxCommand.php"), "utf8");
  const jb = readFileSync(join(ROOT, "src/Activity/EmitJobBreakdownCreated.php"), "utf8");
  const extend = readFileSync(join(ROOT, "extend.php"), "utf8");

  assert.match(client, /FLATRATE_ACTIVITY_EMIT_ENABLED/);
  assert.match(client, /DEFAULT_TIMEOUT_SECONDS = 2\.5/);
  assert.match(emitter, /function enabled\(\): bool/);
  assert.match(emitter, /resolveSub/);
  assert.match(emitter, /flatrate_activity_skipped_no_identity/);
  assert.match(emitter, /must never roll back/i);
  assert.match(emitter, /error_class/);
  assert.doesNotMatch(emitter, /getMessage\s*\(/);
  assert.match(vote, /vote_transition/);
  assert.match(vote, /VoteStateStore/);
  assert.match(vote, /emitter->enabled\(\)/);
  assert.match(vote, /flatrate_activity_vote_observer_failed/);
  assert.match(vote, /error_class/);
  assert.doesNotMatch(vote, /getMessage\s*\(/);
  assert.match(brand, /position === null/);
  assert.match(brand, /ACCEPTED_BRAND_SLUGS/);
  assert.match(brand, /alpha-romeo/);
  assert.match(outbox, /claimDue/);
  assert.match(outbox, /terminal_at/);
  assert.match(outbox, /redacted/);
  assert.match(drainer, /ActivityClient \$client/);
  assert.doesNotMatch(drainer, /private object \$client/);
  assert.match(drainer, /error_class/);
  assert.doesNotMatch(drainer, /getMessage\s*\(/);
  assert.match(command, /ActivityOutboxDrainer \$drainer/);
  assert.match(command, /parent::__construct\s*\(/);
  assert.doesNotMatch(command, /\$this->container/);
  assert.match(jb, /marker_scope'\s*=>\s*'post'/);
  assert.match(jb, /createdByStarter/);
  assert.doesNotMatch(jb, /marker_scope'\s*=>\s*'reply'/);
  assert.match(extend, /ActivityServiceProvider/);
  assert.match(extend, /DrainActivityOutboxCommand/);
  assert.match(extend, /everyMinute/);
  assert.match(extend, /flatrate-activity\.emit_enabled/);
  assert.equal(extend.includes("email_identity"), false);
});

test("R1/R2 behavior PHP harnesses are wired", () => {
  const behavior = readFileSync(join(ROOT, "test/activity-r1-behavior.php"), "utf8");
  const r2 = readFileSync(join(ROOT, "test/activity-r2-behavior.php"), "utf8");
  const ci = readFileSync(join(ROOT, ".github/workflows/ci.yml"), "utf8");
  assert.match(behavior, /ACTIVITY_R1_BEHAVIOR_PASS/);
  assert.match(behavior, /fresh nonce each attempt/);
  assert.match(behavior, /secondary warranty/);
  assert.match(r2, /ACTIVITY_R2_BEHAVIOR_PASS/);
  assert.match(r2, /COMMAND_CONSTRUCTOR_INJECTION/);
  assert.match(r2, /EMIT_OFF_VOTE_STATE_MUTATION/);
  assert.match(ci, /activity-r1-behavior\.php/);
  assert.match(ci, /activity-r2-behavior\.php/);
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
