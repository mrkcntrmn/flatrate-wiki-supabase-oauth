# FORUM-IA-013 — Shared grouped forum navigation

Status: **IMPLEMENTED IN SOURCE — NOT YET LIVE**

## Intent

Replace the Brands-only presentation authority with one shared forum navigation
contract consumed by both desktop sidebar and mobile drawer renderers.

Public presentation groups are not Flarum parent tags:

```text
PUBLIC_INFORMATION_ARCHITECTURE
!=
FLARUM_TAG_PARENT_GRAPH
```

## Contract

```text
PUBLIC_GROUP_ORDER=
Community,Technician Topics,Brands

COMMUNITY_BOARD_COUNT=2
TECHNICIAN_TOPIC_BOARD_COUNT=0
BRAND_BOARD_COUNT=41

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

Current resolved visible groups (Technician Topics empty):

```text
Community
Brands
```

## Corrected names / legacy slugs

```text
Alfa Romeo → /t/alpha-romeo
Genesis    → /t/genisis
McLaren    → /t/mclaren
```

## Production taxonomy

IA-013 does not mutate production tags.

```text
TAG_WRITES=0
TAG_ORDER_POSTS=0
IA013_PRODUCTION_DEPLOYED=false
```

Deployment assessment belongs to a later FORUM-IA-014 tranche.
