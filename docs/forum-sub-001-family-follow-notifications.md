# FORUM-SUB-001 — GM/CDJR family follow notification inheritance

## Product contract

```text
TAG_INHERITANCE=NO
NOTIFICATION_INHERITANCE=YES
GM_FLARUM_PARENT=false
CDJR_FLARUM_PARENT=false
```

GM and CDJR remain ordinary independent FlatRate boards. This feature changes
**notification state resolution only**. It does not introduce Flarum tag
hierarchy and does not alter IA-013 presentation.

## Families (stable slugs)

```text
GM_FAMILY=gm,buick,cadillac,chevrolet,gmc
CDJR_FAMILY=cdjr,chrysler,dodge,jeep,ram
FAMILY_ROOT_COUNT=2
FAMILY_CHILD_COUNT=8
FAMILY_MEMBER_COUNT=10
FAMILY_OVERLAP_COUNT=0
```

Following a family root provides notification fallback for that family's
children. Following a child covers that child only (no upward or sibling
inheritance).

## State precedence

```text
NULL_DIRECT_SUBSCRIPTION_POLICY=FALL_BACK_TO_FAMILY_ROOT
```

For a family child:

1. Non-null direct Follow Tags state wins (`follow`, `lurk`, `ignore`, `hide`)
2. Otherwise fall back to the family-root subscription
3. If the root also has no subscription → null/default

`tag_user` row existence with a null subscription is **not** treated as an
explicit opt-out (the table is shared with Tags read/hidden metadata).

## No fanout writes

Following GM/CDJR does **not** create child `tag_user` subscription rows.

```text
FAMILY_FOLLOW_FANOUT_WRITES=0
SUB001_MIGRATION_COUNT=0
```

FoF Follow Tags subscription API/storage remains unchanged.

## Integration seam

FoF 1.3.0 chooses recipients inside queued jobs. Flarum's `beforeSending`
hook runs **after** `NotificationSyncer` reconciles existing notification
rows, so it must not be used to **add** family recipients.

FlatRate therefore registers a conditional subclass:

```text
FamilyAwareNotificationSyncer
  extends Flarum\Notification\NotificationSyncer
```

It resolves the final recipient list **before** `parent::sync()`, only for:

* `FoF\FollowTags\Notifications\NewDiscussionBlueprint`
* `FoF\FollowTags\Notifications\NewPostBlueprint`
* `FoF\FollowTags\Notifications\NewDiscussionTagBlueprint`

Inherited candidates have **not** already passed FoF visibility queries.
Discussion and post visibility checks therefore **fail closed**: any
exception or missing positive check makes the recipient ineligible.

All other blueprints pass through unchanged.

Registration is gated with Flarum 1.8.19 `Extend\Conditional::whenExtensionEnabled('fof-follow-tags', ...)`.
There is no hard Composer dependency on `fof/follow-tags`.

Runtime proofs (binding, resolver, visibility exceptions) live in the
disposable harness under `test/harness/forum-sub001-runtime/` and
`test/forum-sub001-runtime.php`.

## Inherited ignore + mentions

Inherited root `ignore` suppresses mention notifications on family children
unless a non-null child state overrides it. That filter uses `beforeSending`
**only to remove** recipients (same architectural pattern as FoF's own ignore
filter).

## Deferred

```text
FOLLOWING_PAGE_FAMILY_INHERITANCE=DEFERRED
FAMILY_FOLLOW_UI_HELPER=DEFERRED
```

Notification correctness is the SUB-001 contract. Following-page search and
UI helper copy are out of scope for this tranche.
