# FORUM-SUB-000A — FoF Follow Tags 1.3.0 teaser-only email policy

Status: **SOURCE_IMPLEMENTED_NOT_LIVE**

Authority: **companion source**

```text
FOLLOW_TAGS_VERSION_QUALIFIED=1.3.0

EMAIL_POLICY_BEFORE=FAIL
EMAIL_POLICY_SOURCE_FIX=IMPLEMENTED
EMAIL_POLICY_PRODUCTION_VERIFIED=false

FOLLOW_TAGS_INSTALLED=false
```

## Purpose

FORUM-SUB-000 qualified `fof/follow-tags:1.3.0` for Composer/migrations, then blocked install on:

```text
BLOCKED_EMAIL_POLICY_REGRESSION
```

Upstream 1.3.0 email views for new discussion and new post map `{post_content}` / `$blueprint->post->content`. FlatRate policy requires zero discussion/post/reply body content in notification emails.

## Source fix

Companion `extend.php` extends the existing `Extend\View` chain with:

```text
fof-follow-tags → views/fof-follow-tags
```

Overrides (content-leaking views only):

```text
views/fof-follow-tags/emails/newDiscussion.blade.php
views/fof-follow-tags/emails/newPost.blade.php
```

`newTag` is not overridden: upstream 1.3.0 `newTag.blade.php` has no post/discussion body path.

FlatRate-owned locale keys live under:

```text
flatrate-email-policy.email.follow_tags.*
```

Teaser contract preserves recipient, actor, discussion title, and deep link. No `{post_content}`, excerpts, or truncations.

## Pre-install safety

Flarum 1.8.19 `Extend\View::extendNamespace` → Illuminate `FileViewFinder::prependNamespace` safely registers hints for a namespace that does not yet exist. No Follow Tags Composer dependency is added. Companion remains bootable while Follow Tags is absent.

## Not in this tranche

- production Follow Tags install
- `/data/extensions/list` mutation
- PikaPods restart
- GM/CDJR family-follow semantics (FORUM-SUB-001)
- reuse of the SUB-000 install bundle hash (invalid after companion source change)
