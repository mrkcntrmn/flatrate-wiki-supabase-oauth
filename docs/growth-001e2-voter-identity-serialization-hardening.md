# GROWTH-001E2 — Voter identity serialization hardening

Status: **SOURCE + PRODUCTION PRIVACY PROMOTION** (gate remains closed)  
Tracking: FlatRate.wiki #334  
PR: `mrkcntrmn/flatrate-wiki-supabase-oauth` #59

## RCA

```text
RCA_CLASS=SERIALIZER_RELATIONSHIP_PERMISSION_BYPASS
FOF_VERSION=1.6.12
```

FoF Gamification 1.6.12 registers unguarded `PostSerializer` relationships:

```php
->hasMany('upvotes', BasicUserSerializer::class)
->hasMany('downvotes', BasicUserSerializer::class)
```

and exposes them via `addOptionalInclude(['upvotes','downvotes'])` on post API controllers.

Separately, `AddPostData` sets `seeVoters` from:

```text
actor.can('canSeeVoters', discussion) && actor.can('canSeeVoters', post)
```

That attribute does **not** gate relationship serialization. Unauthenticated:

```http
GET /api/posts/{id}?include=upvotes
```

therefore returned voter user IDs while `seeVoters=false` (GROWTH-001E1 launch blocker).

This is **not** a permission-row misconfiguration.

## Public privacy contract

```text
PUBLIC_VOTER_IDENTITY=false
ORDINARY_MEMBER_VOTER_IDENTITY=false
MODERATOR_VOTER_AUDIT=true
ADMIN_VOTER_AUDIT=true
```

Predicate (must match FoF attribute path):

```text
CAN_SEE_VOTERS =
  actor.can('canSeeVoters', post.discussion)
  &&
  actor.can('canSeeVoters', post)
```

Unauthorized / unexpected relationship name / any `Throwable` ⇒ relationship callback returns `null` (fail closed).  
`canSeeVotes` alone must never expose identities.

Privacy works with `flatrate-voting.enabled` true **or** false.

## Boot-time override architecture

`VotingServiceProvider::boot()` (after extension extenders) re-registers:

```text
Extend\ApiSerializer(PostSerializer)::relationship(upvotes|downvotes)
```

via `VoterIdentityRelationshipGuard`. Flarum 1.8.19 boot order:

1. `booting` callbacks → `ExtensionManager::extend` (FoF unguarded `hasMany`)
2. service provider `boot()` (FlatRate override wins via `AbstractSerializer::setRelationship`)

Do not rely on extension-list ordering alone.

## Readiness blocker

```text
privacy.voter_relationship_guard_registered=true
```

required for `safe_to_enable`. If false:

```text
blocking_reasons += voter_identity_serializer_guard_unavailable
```

Also exposes residual row aggregates (no IDs):

```text
provider.vote_row_count_total
provider.vote_row_count_active   # value != 0
provider.vote_row_count_neutral  # value = 0
```

`total > 0` is **not** a generic launch blocker. Neutral `value=0` rows are valid FoF removal history — do not DELETE.

## Production promotion boundary

001E2 may promote the exact qualified OAuth SHA while keeping:

```text
flatrate-voting.enabled=false
fof/gamification=1.6.12
PR #59 OPEN/unmerged
```

Production active-vote privacy retest and voting relaunch are **GROWTH-001E3**.
