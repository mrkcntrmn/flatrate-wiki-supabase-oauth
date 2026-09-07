# FORUM-IA-013 — Shared grouped forum navigation

Status: **SUPERSEDED FOR CURRENT MEMBERSHIP BY FORUM-IA-015**

IA-013 shipped the shared `FlatRateForumNavigation` contract and desktop/mobile
renderers. FORUM-IA-015 keeps that architecture and updates live membership plus
DiscussionPage desktop persistence. Historical IA-013 membership below is
preserved for provenance; current/live contract belongs to IA-015.

## Intent

Replace the Brands-only presentation authority with one shared forum navigation
contract consumed by both desktop sidebar and mobile drawer renderers.

Public presentation groups are not Flarum parent tags:

```text
PUBLIC_INFORMATION_ARCHITECTURE
!=
FLARUM_TAG_PARENT_GRAPH
```

## Original IA-013 contract (historical)

When IA-013 first landed, presentation membership was:

```text
PUBLIC_GROUP_ORDER=
Community,Technician Topics,Brands

COMMUNITY_BOARD_COUNT=2
TECHNICIAN_TOPIC_BOARD_COUNT=0
BRAND_BOARD_COUNT=41

EMPTY_TECHNICIAN_TOPICS_UI_POLICY=
HIDE_UNTIL_NONEMPTY

Community children=
Start Here, General Shop Discussion

Technician Topics children=
(empty / hidden)
```

## Current / live contract (FORUM-IA-015)

```text
PUBLIC_GROUP_ORDER=
Community,Technician Topics,Brands

COMMUNITY_BOARD_COUNT=1
TECHNICIAN_TOPIC_BOARD_COUNT=1
BRAND_BOARD_COUNT=41

Community
  Start Here
Technician Topics
  General Shop Discussion
Brands
  41 boards

EMPTY_TECHNICIAN_TOPICS_UI_POLICY=
HIDE_UNTIL_NONEMPTY

GM_FLARUM_PARENT=false
CDJR_FLARUM_PARENT=false

GM_NAV_CHILDREN=
Buick,Cadillac,Chevrolet,GMC

CDJR_NAV_CHILDREN=
Chrysler,Dodge,Jeep,Ram

PUBLIC_INFORMATION_ARCHITECTURE_NE_FLARUM_PARENT_GRAPH=true
```

Canonical source:

```text
js/dist/forum-navigation.js
window.FlatRateForumNavigation
```

Desktop surfaces (IA-015):

```text
IndexPage
DiscussionPage
```

Mobile:

```text
HeaderSecondary drawer
```

## Corrected names / legacy slugs

```text
Alfa Romeo → /t/alpha-romeo
Genesis    → /t/genisis
McLaren    → /t/mclaren
```

## Production taxonomy

IA-013 / IA-015 do not mutate production tags.

```text
TAG_WRITES=0
TAG_ORDER_POSTS=0
FLARUM_TAG_PARENT_CHANGE=false
```

See `docs/forum-ia-015-persistent-grouped-navigation.md` for DiscussionPage
persistence and the General Shop Discussion regrouping.
