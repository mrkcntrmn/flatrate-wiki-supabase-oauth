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

`js/dist/plain-voting.js` suppresses ordinary-user FoF points/ranks/Votes tab/hot/votes sorts/rankings nav, and hides vote controls while the FlatRate gate is closed. When the gate is open, FlatRate forces FoF's upvote-only presentation with a thumbs-up icon. The downvote control is hidden defensively in CSS. Vote chrome is three-state: white thumb/count at zero upvotes; white thumb with lime `#84cc16` count when upvotes exist; brand pink `#c72d5d` thumb and count when the viewer has upvoted. Classes `FlatRateVotes--zero` / `--hasVotes` / `--mine` are applied from `plain-voting.js`.

### CommentPost presentation contract

```text
POST_HEADER_CONTROLS_ALIGNMENT=USERNAME_ROW
POST_CONTROLS_HORIZONTAL_POSITION=RIGHT
POST_ACTION_ROW=SINGLE_ROW
REPLY_ACTION_POSITION=CENTER
UPVOTE_ACTION_POSITION=RIGHT
UPVOTE_ICON_COUNT_LAYOUT=INLINE_HORIZONTAL
UPVOTE_COUNT_POSITION=RIGHT_OF_ICON
POST_VOTE_DOM_OWNER=POST_ACTIONS
```

Reply and thumbs/count share one action row. Reply is centered. Thumbs/count are right-justified. Thumb and count are one inline horizontal unit (`👍 1`), never a stacked vertical provider box. The `⋯` controls sit on the right side of the username/time header row (same vertical center), not merely “somewhere near the top of the post.”

### Provider layout parity

FoF Gamification 1.6.12 registers the header-style vote widget when `fof-gamification.altPostVotingUi` is truthy (`!!parseInt(app.data[...])` at FoF initializer time). FlatRate normalizes `fof-gamification.altPostVotingUi = '0'` in a **priority-100** initializer so it runs before FoF and CommentPost keeps votes in `actionItems` (`.Post-actions .item-votes`), not `.Post-header .item-votes .Post-votes`.

`fof-gamification.useAlternateLayout` also affects discussion-list alternate chrome. FlatRate does **not** force that flag from the frontend; harness seeds `0` for presentation parity. Production must keep `altPostVotingUi=0` for this CommentPost contract — treat drift as runtime configuration drift, not a CSS-only fix.

### Discussion aggregate upvote header

```text
POST_UPVOTE=
  which specific contribution receives the member's ballot
DISCUSSION_UPVOTE_TOTAL=
  count of positive post_votes across ALL visible comment posts
  in the discussion (not FoF discussion.votes / first-post-only)
DISCUSSION_HEADER_ACTIVE=
  viewer currently has a positive ballot somewhere in the discussion
ONE_EFFECTIVE_POSITIVE_BALLOT_PER_MEMBER_PER_DISCUSSION=true
CANONICAL_STORAGE=FOF_POST_VOTES
NEW_VOTE_TABLE=false
```

DiscussionPage sidebar renders `FlatRateDiscussionVote` opposite Following:

- inactive (no viewer ballot): lime `#84cc16` thumb+count; clickable → upvotes opening post
- active (viewer ballot anywhere): pink `#c72d5d`; not clickable (remove from the voted post)
- voting a different reply **moves** the ballot (zeros the prior positive row) so aggregate stays stable

API attributes on `BasicDiscussionSerializer` (no voter identities):

- `flatRateDiscussionUpvotes`
- `flatRateDiscussionViewerUpvoted`
- `flatRateDiscussionViewerVotePostId`
- `flatRateDiscussionCanUpvote`

FoF legacy `discussion.votes` remains a provider implementation detail and is **not** FlatRate's whole-discussion total.

The file must retain `module.exports = {}` (Flarum 1.8 webpack CJS entry).
