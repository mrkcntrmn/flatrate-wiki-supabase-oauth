#!/usr/bin/env node
/**
 * GROWTH-001B — qualified FoF Gamification pin must remain exact.
 */
import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const EXPECTED_VERSION = "1.6.12";
const EXPECTED_SHA = "6be68f005b7db3036ca67a7b807bc4531972ed19";

test("docs and readiness pin exact FoF Gamification 1.6.12 SHA", () => {
  const doc = readFileSync(
    join(ROOT, "docs/growth-001b-plain-vote-foundation.md"),
    "utf8",
  );
  assert.match(doc, new RegExp(EXPECTED_VERSION));
  assert.match(doc, new RegExp(EXPECTED_SHA));

  const composer = JSON.parse(readFileSync(join(ROOT, "composer.json"), "utf8"));
  assert.equal(composer.require["fof/gamification"], undefined);
  assert.equal(composer.require["flarum/pusher"], undefined);
});

test("VoteSafetyGate probes exact Pusher binding key", () => {
  const src = readFileSync(join(ROOT, "src/Voting/VoteSafetyGate.php"), "utf8");
  assert.match(src, /bound\('Pusher'\)/);
  assert.doesNotMatch(src, /Pusher\\\\Pusher/);
});
