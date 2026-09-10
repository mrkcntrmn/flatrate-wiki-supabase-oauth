# REP-001F.A1 — FoF Gamification vote emission seam

```text
fof/gamification=1.6.12
SHA=6be68f005b7db3036ca67a7b807bc4531972ed19
FLARUM_TARGET=1.8.19
```

## Chosen post-success hook

```text
FoF\Gamification\Events\PostWasVoted
```

Dispatched from `SaveVotesToDatabase::vote()` **after** `$vote->save()`.

Event payload: `Vote $vote` (post, user, effective value in {-1,0,1}).

## Previous effective value

`PostWasVoted` does not carry previous value. FlatRate therefore owns:

```text
flatrate_vote_activity_state(post_id, user_id, last_effective_vote, state_version)
```

Reconciliation:

```text
no tracker row + wasRecentlyCreated
  -> treat prior as 0, emit transition, version=1
no tracker row + existing FoF vote
  -> seed baseline with current value, no emit
  (do not falsely call a change an initial cast)
tracker row present
  -> compare last_effective_vote; emit only on change; increment version
```

## Frontend voting is non-authoritative

Emission is server-side only. Mithril/saveVote.js is not a producer.

## Analytics isolation

Emitter catches all delivery failures. Canonical vote/post actions are not rolled back.

## Pusher privacy (A2 preflight)

Upstream `pushNewVote` may bind `Pusher::class`. A2 must prove public identity broadcast is neutralized before enabling voting in production.
