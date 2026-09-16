# REP-001F.A1 — activity emitter (Flarum extension)

Status: **R2_SOURCE_COMPLETE / PR_OPEN / AWAITING_SCOPED_RE_REVIEW**  
Updated: **2026-09-10**

```text
TRANCHE=REP-001F.A1_EXTENSION_R2
FORUM_ACTIVITY_BRIDGE_SCHEMA_VERSION=1
HMAC_PROTOCOL_VERSION=1
ACTIVITY_EMIT_DEFAULT_ENABLED=false
FLATRATE_ACTIVITY_EMIT_ENABLED=false
DELIVERY_SEMANTICS=AT_LEAST_ONCE
OUTBOX_IMPLEMENTED=true
OUTBOX_AUTOMATIC_DRAIN_PATH=external_cloudflare_cron:POST_/api/flatrate-activity/drain
OUTBOX_NATIVE_FLARUM_SCHEDULER_PATH=flarum_schedule:flatrate:activity:drain-outbox
OUTBOX_NATIVE_HOST_EXECUTOR=ABSENT_ON_PIKAPODS
OUTBOX_COMMAND_RUNTIME_DI=PASS
SCHEDULED_DRAIN_RUNTIME_CONTRACT=PASS
EXTERNAL_DRAIN_ENDPOINT=POST_/api/flatrate-activity/drain
EXTERNAL_DRAIN_AUTH=Bearer_token_sha256_digest_only
EXTERNAL_DRAIN_SETTING=flatrate-activity.drain_token_sha256
EXTERNAL_DRAIN_ENV=FLATRATE_ACTIVITY_DRAIN_TOKEN_SHA256
EXTERNAL_DRAIN_CALLER_ARGS=none
EXTERNAL_DRAIN_DEFAULT_BATCH=25
RETRY_NEW_NONCE_EACH_ATTEMPT=true
TERMINAL_PAYLOAD_RETENTION_BOUNDED=true
BRAND_ATTRIBUTION_CONTRACT=PASS
PRIMARY_TAG_ONLY=true
BRAND_ALLOWLIST_ENFORCED=true
SECONDARY_TAG_CAN_BECOME_BRAND=false
PROFILE_AFFILIATION_USED=false
GM_CDJR_DOUBLE_COUNT=false
JOB_BREAKDOWN_MARKER_SCOPE=post
JOB_BREAKDOWN_CREATED_BY_STARTER_DERIVED=true
JOB_BREAKDOWN_ACT_CROSS_CONTRACT=PASS
VOTE_ACTIVITY_FAILURE_BOUNDARY=PASS
EMIT_OFF_VOTE_STATE_MUTATION=false
ACTIVITY_ERROR_CAN_FAIL_CANONICAL_VOTE=false
ACTIVITY_LOG_IDENTITY_LEAK_GUARD=PASS
RAW_THROWABLE_MESSAGE_IN_ACTIVITY_LOG=false
VOTE_PROVIDER=fof/gamification:1.6.12
VOTE_PROVIDER_SOURCE_SHA=6be68f005b7db3036ca67a7b807bc4531972ed19
VOTE_POST_SUCCESS_SEAM=FoF\\Gamification\\Events\\PostWasVoted
VOTE_EVENT_LISTENER_KEY_LEADING_SLASH=false
VOTE_EVENT_LISTENER_RUNTIME_MATCH=true
VOTE_STATE_VERSION_SOURCE=flatrate_vote_activity_state
IDENTITY_SOURCE=login_providers(provider=flatrate,identifier=sub)
CANONICAL_ACTION_FAILS_ON_ACTIVITY_ERROR=false
HISTORICAL_POST_VOTE_MIGRATION_REQUIRED=false
VOTE_PERMISSION_OPENS_AFTER_EMITTER=true
PRODUCTION_MUTATION=false
READY_FOR_A1_EXTENSION_MERGE=false_pending_scoped_R2_re_review
```

## Flow

```text
canonical Flarum/FoF success
  -> FlatRate observation (+ vote state version) [feature-gated first]
  -> durable outbox
  -> HMAC POST /api/internal/forum-activity
  -> on failure: markFailure / markTerminal
  -> ActivityOutboxDrainer (claim lease + fresh HMAC each attempt)
       ↑
       ├── Flarum schedule everyMinute (supported; host cron may be absent)
       ├── CLI flatrate:activity:drain-outbox (operator recovery)
       └── POST /api/flatrate-activity/drain (external clock; digest auth)
```

### Primary production retry executor (qualified source)

```text
Cloudflare Cron (* * * * *)
  -> POST https://forum.flatrate.wiki/api/flatrate-activity/drain
  -> Authorization: Bearer <ACTIVITY_DRAIN_TOKEN>
  -> forum compares sha256(token) to flatrate-activity.drain_token_sha256
     (or FLATRATE_ACTIVITY_DRAIN_TOKEN_SHA256 env digest)
  -> ActivityOutboxDrainer::drain()  // default batch 25; no caller args
```

Forum stores **digest only**. Raw token lives only in Worker secret storage
(after a future deploy work order). Endpoint is inert until a digest is configured
(`503 scheduler_not_configured`).

### Native Flarum scheduler

Supported in source (`everyMinute()->withoutOverlapping()`), but **not invoked**
on PikaPods today (no usable host cron / application console). Kept for
compatibility if the host later gains `php flarum schedule:run`.

CLI `php flarum flatrate:activity:drain-outbox` remains operator recovery where
a console exists — not a production dependency.

Host requirement (native path only): cron `* * * * * php flarum schedule:run`.

Command DI: `DrainActivityOutboxCommand` constructor-injects `ActivityOutboxDrainer`;
drainer constructor-injects `ActivityClient` (not builtin `object`).

### Threat model (external drain)

```text
Threat: unauthenticated internet request
Control: bearer token + digest-only server storage + hash_equals

Threat: token database disclosure
Control: database stores digest only

Threat: credential replay
Impact: only bounded drain invocation (no args / no row selection)
Controls: DB claim lease, idempotent source_event_key, TLS

Threat: concurrent Worker + native scheduler
Control: lockForUpdate + available_at lease

Threat: endpoint used as outbox management API
Control: empty body required; no limit/id/payload parameters

Threat: secret leaks in logs
Control: log event name + error_class only on drain failure
```

Optional later hardening (not required for R1 qualification): Cloudflare Access
service token on exact path, WAF rate limit, rotation automation, terminal-row alerts.

## Brand attribution

```text
CONTRIBUTION_BRAND_ATTRIBUTION=discussion primary accepted brand context
primary tag: position !== null
secondary tag: position === null (never brand_slug)
allowlist: FlatRate boards-target brands (legacy alpha-romeo / genisis preserved)
```

## Job Breakdown observation

```text
marker_scope=post
created_by_starter=derived from discussion.user_id === actor.id
```

## Feature gate

Env `FLATRATE_ACTIVITY_EMIT_ENABLED` or setting `flatrate-activity.emit_enabled` (default false).
Missing/short secret or missing URL → fail closed (no emit).
Vote observer checks `ActivityEmitter::enabled()` before `VoteStateStore` mutation.

## Key files

```text
src/Activity/*
migrations/2026_09_10_000000_create_activity_emitter_tables.php
migrations/2026_09_10_120000_activity_outbox_terminal_at.php
test/fixtures/forum-activity-hmac-v1.json
test/forum-activity-hmac-vector.php
test/forum-activity-emitter-contract.test.mjs
test/activity-r1-behavior.php
test/activity-r2-behavior.php
docs/rep-001fa1-vote-emission-seam.md
```
