# GROWTH-001B — FlatRate plain-vote foundation

Status: **SOURCE_QUALIFICATION**  
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
        + GET /api/flatrate-voting/readiness (admin-only)
        + existing Activity soft-bind on PostWasVoted
```

## Hard rules

```text
OAUTH_RUNTIME_GAMIFICATION_REQUIRE_ADDED=false
PUSHER_RUNTIME_DEPENDENCY_ADDED=false
FLATRATE_VOTE_GATE_DEFAULT_ENABLED=false
FLATRATE_POLICY_FORCE_ALLOW=false
SECOND_CANONICAL_VOTE_STORE=false
UPSTREAM_PUBLIC_RANKINGS=false
SELF_VOTE_SAFETY_INDEPENDENT_OF_PROVIDER_SETTING=true
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
- provider settings + normalized permission booleans
- `safe_to_enable` + deterministic `blocking_reasons`

No secrets, user IDs, post IDs, or row payloads.

## Production sequence (future)

1. Qualify this source candidate (GROWTH-001B)
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

`js/dist/plain-voting.js` suppresses ordinary-user FoF points/ranks/Votes tab/hot/votes sorts/rankings nav, and hides vote controls while the FlatRate gate is closed.
