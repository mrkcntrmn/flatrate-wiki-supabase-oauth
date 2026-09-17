# GROWTH-001B — FlatRate plain-vote foundation

Status: **SOURCE_QUALIFICATION** (+ GROWTH-001B1 fail-closed hardening)  
Tracking: FlatRate.wiki #334

## Architecture

```text
FoF Gamification 1.6.12 (optional soft dependency)
QUALIFIED_PROVIDER_SOURCE_SHA=6be68f005b7db3036ca67a7b807bc4531972ed19
        |
        | canonical post_votes persistence
        v
FlatRate Voting layer (this extension)
        + flatrate-voting.enabled (default false)
        + VoteSafetyGate (fail-closed)
        + PostVotePolicy (FORCE_DENY / abstain)
        + GlobalVotingPolicy (rankings FORCE_DENY for non-admins)
        + VoterIdentityRelationshipGuard (PostSerializer upvotes/downvotes)
        + GET /api/flatrate-voting/readiness (admin-only)
        + existing Activity soft-bind on PostWasVoted
```

## Voter identity privacy (GROWTH-001E2)

FoF 1.6.12 `hasMany(upvotes|downvotes)` is unguarded. FlatRate overrides those
relationships in `VotingServiceProvider::boot()` so voter identities serialize
only when `canSeeVoters` holds for **both** discussion and post. Readiness
requires `privacy.voter_relationship_guard_registered=true` (blocker:
`voter_identity_serializer_guard_unavailable`). See
`docs/growth-001e2-voter-identity-serialization-hardening.md`.

## Qualification scope

```text
001B safety foundation qualified
provider mutation semantics NOT_RUN in this tranche
001C deploys only safety/readiness layer
001D performs provider install/staged provider behavior verification
001E opens member voting
```

Do not treat 001B/001B1 as FoF canonical vote-behavior qualification.

## Hard rules

```text
OAUTH_RUNTIME_GAMIFICATION_REQUIRE_ADDED=false
PUSHER_RUNTIME_DEPENDENCY_ADDED=false
FLATRATE_VOTE_GATE_DEFAULT_ENABLED=false
FLATRATE_POLICY_FORCE_ALLOW=false
SECOND_CANONICAL_VOTE_STORE=false
UPSTREAM_PUBLIC_RANKINGS=false
SELF_VOTE_SAFETY_INDEPENDENT_OF_PROVIDER_SETTING=true
PERMISSION_READ_ERROR_ALLOW_ENABLE=false
```

## Readiness endpoint

```text
GET /api/flatrate-voting/readiness
ADMIN_ONLY=true
```

Proves (without shell / external DB exposure):

- `Pusher` container binding (`container->bound('Pusher')`)
- prefix-aware `flatrate_vote_activity_state` schema
- outbox companion `flatrate_activity_outbox.terminal_at`
- likes / post_votes aggregate presence
- provider settings + tri-state permission facts (`true` / `false` / `null`)
- `permissions.inspection_ok`
- `safe_to_enable` + deterministic `blocking_reasons`

Permission inspection failure:

```text
permission query failed / UNKNOWN
=> safe_to_enable=false
=> blocking_reasons includes permission_state_unavailable
```

UNKNOWN must never masquerade as `false`.

No secrets, user IDs, post IDs, or row payloads.

## Policy force-deny

FlatRate hard safety boundaries return `FORCE_DENY` (not ordinary `DENY`) so they remain authoritative even if another policy later returns `FORCE_ALLOW`.

Safe FlatRate vote state abstains (`null`) so FoF owns ordinary `discussion.votePosts` semantics.

## Production sequence (future)

1. Qualify this source candidate (GROWTH-001B / 001B1)
2. GROWTH-001C — deploy safety/readiness only (Gamification absent, gate=false)
3. Query readiness → close Pusher + Activity schema blockers
4. GROWTH-001D — install exact `fof/gamification:1.6.12` with gate CLOSED
5. Normalize settings/permissions; `safe_to_enable=true`
6. GROWTH-001E — open `flatrate-voting.enabled` LAST; controlled transitions

Never:

```text
open voting -> then fix privacy
```

## UI

`js/dist/plain-voting.js` suppresses ordinary-user FoF points/ranks/Votes tab/hot/votes sorts/rankings nav, and hides vote controls while the FlatRate gate is closed. Must retain `module.exports = {}` (Flarum 1.8 webpack CJS entry).
