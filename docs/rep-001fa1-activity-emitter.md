# REP-001F.A1 — activity emitter (Flarum extension)

Status: **SOURCE_IMPLEMENTATION / PR_OPEN**  
Updated: **2026-09-10**

```text
TRANCHE=REP-001F.A1_EXTENSION
FORUM_ACTIVITY_BRIDGE_SCHEMA_VERSION=1
HMAC_PROTOCOL_VERSION=1
ACTIVITY_EMIT_DEFAULT_ENABLED=false
FLATRATE_ACTIVITY_EMIT_ENABLED=false
DELIVERY_SEMANTICS=AT_LEAST_ONCE
OUTBOX_IMPLEMENTED=true
VOTE_PROVIDER=fof/gamification:1.6.12
VOTE_PROVIDER_SOURCE_SHA=6be68f005b7db3036ca67a7b807bc4531972ed19
VOTE_POST_SUCCESS_SEAM=FoF\\Gamification\\Events\\PostWasVoted
VOTE_STATE_VERSION_SOURCE=flatrate_vote_activity_state
IDENTITY_SOURCE=login_providers(provider=flatrate,identifier=sub)
CANONICAL_ACTION_FAILS_ON_ACTIVITY_ERROR=false
PRODUCTION_MUTATION=false
```

## Flow

```text
canonical Flarum/FoF success
  -> FlatRate observation (+ vote state version)
  -> durable outbox
  -> HMAC POST /api/internal/forum-activity
  -> retry with fresh nonce on 5xx/timeout
```

## Feature gate

Env `FLATRATE_ACTIVITY_EMIT_ENABLED` or setting `flatrate-activity.emit_enabled` (default false).
Missing/short secret or missing URL → fail closed (no emit).

## Key files

```text
src/Activity/*
migrations/2026_09_10_000000_create_activity_emitter_tables.php
test/fixtures/forum-activity-hmac-v1.json
test/forum-activity-hmac-vector.php
test/forum-activity-emitter-contract.test.mjs
docs/rep-001fa1-vote-emission-seam.md
```
