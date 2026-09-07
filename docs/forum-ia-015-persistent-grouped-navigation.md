# FORUM-IA-015 — Persistent desktop grouped navigation

Status: **IMPLEMENTED IN SOURCE — NOT YET LIVE**

## Intent

Two presentation corrections on top of FORUM-IA-013:

1. Move **General Shop Discussion** from Community into Technician Topics
   (presentation membership only; no Flarum tag parent/slug/name/position change).
2. Keep FlatRate grouped navigation available while reading discussions on desktop
   by extending Flarum 1.8.19 `DiscussionPage.sidebarItems()`, not DOM injection.

## Current live presentation contract

```text
Community
  Start Here
Technician Topics
  General Shop Discussion
Brands
  41 boards (33 top-level presentation entries + 8 GM/CDJR children)
```

```text
GROUP_ORDER=community,technician-topics,brands
COMMUNITY_BOARD_COUNT=1
TECHNICIAN_TOPIC_BOARD_COUNT=1
BRAND_BOARD_COUNT=41
PRESENTED_PRIMARY_BOARD_COUNT=43
PUBLIC_PRESENTATION_GROUP_CHANGE=true
FLARUM_TAG_PARENT_CHANGE=false
```

Canonical source remains:

```text
js/dist/forum-navigation.js
window.FlatRateForumNavigation
```

## Desktop surfaces

Grouped navigation renders through the supported sidebar ItemList on:

```text
IndexPage.sidebarItems   → FlatRateForumNav--index     priority=-20
DiscussionPage.sidebarItems → FlatRateForumNav--discussion priority=-200
```

DiscussionPage uses the native `DiscussionPage-nav` container. Native
`controls` (priority 100) and `scrubber` (priority -100) stay ahead of FlatRate
navigation. Long Brands lists on discussion pages scroll inside
`.FlatRateForumNav--discussion` only; native sticky `DiscussionPage-nav`
behavior is left alone.

```text
DESKTOP_NAV_DOM_INJECTION=false
DISCUSSION_ACTIVE_TAG_HIGHLIGHT=DEFERRED
```

## Mobile

Unchanged architecture:

```text
HeaderSecondary → FlatRateForumNav--drawer
```

Mobile still consumes the shared contract, so General Shop Discussion moves with
the contract automatically. No page-top brand-link regression.

## Upstream seam provenance

Pinned exact Flarum 1.8.19 source:

```text
flarum/framework @ v1.8.19
framework/core/js/src/forum/components/DiscussionPage.tsx
SHA256=6fd54b5a0e07dffaf5da8ead70a9ee1a51903f045864bb33318a8f535830ec23
```

Required seam facts: `sidebar()`, `sidebarItems()`, `DiscussionPage-nav`,
`mainContent()`.
