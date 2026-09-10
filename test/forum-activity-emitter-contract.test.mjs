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
  const extend = readFileSync(join(ROOT, "extend.php"), "utf8");

  assert.match(client, /FLATRATE_ACTIVITY_EMIT_ENABLED/);
  assert.match(client, /DEFAULT_TIMEOUT_SECONDS = 2\.5/);
  assert.match(emitter, /resolveSub/);
  assert.match(emitter, /flatrate_activity_skipped_no_identity/);
  assert.match(emitter, /must never roll back/i);
  assert.match(vote, /vote_transition/);
  assert.match(vote, /VoteStateStore/);
  assert.match(extend, /ActivityServiceProvider/);
  assert.match(extend, /flatrate-activity\.emit_enabled/);
  assert.equal(extend.includes("email_identity"), false);
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
