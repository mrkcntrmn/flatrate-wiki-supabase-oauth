# FORUM-SUB-000D — Flarum 1.8 forum JS registration

Status: **SOURCE_FIX_PR**

Authority: **companion source** (`mrkcntrmn/flatrate-wiki-supabase-oauth`)

```text
FLARUM_VERSION=1.8.19
ROOT_CAUSE_CLASS=FLARUM_FRONTEND_EXTENDER_JS_OVERWRITE_LAST_WINS
PRODUCTION_DEPLOYMENT=false
```

## Root cause

Flarum 1.8.19 `Flarum\Extend\Frontend` stores:

```text
Frontend::css() = APPEND   ($this->css[] = $path)
Frontend::js()  = SCALAR_OVERWRITE   ($this->js = $path)
```

Source: `vendor/flarum/core/src/Extend/Frontend.php` (identical at flarum/framework tag `v1.8.19`).

The companion previously chained three `->js()` calls on one extender:

```text
CHAINED_JS_CALL_COUNT=3
EFFECTIVE_JS_SOURCE_COUNT=1   # only mobile-brand-drawer.js survived
```

That matched production:

```text
LIVE_NAV_CONTRACT_PRESENT=false
LIVE_DESKTOP_INITIALIZER_PRESENT=false
LIVE_MOBILE_INITIALIZER_PRESENT=true
```

## Fix

Register the three existing JS sources through separate `Extend\Frontend('forum')` instances (one JS path each), preserving load order:

1. `js/dist/forum-navigation.js` — `FlatRateForumNavigation` contract
2. `js/dist/forum.js` — desktop sidebar initializer
3. `js/dist/mobile-brand-drawer.js` — mobile drawer initializer

Both LESS files remain on the first extender (`css()` appends safely):

```text
resources/less/forum.less
resources/less/mobile-brand-drawer.less
```

```text
FRONTEND_EXTENDER_COUNT_FOR_FLATRATE_JS=3
MAX_JS_CALLS_PER_EXTENDER=1
EFFECTIVE_JS_SOURCE_COUNT_EXPECTED=3
THREE_SOURCE_FILES=true
IA013_JS_SOURCE_CHANGED=false
FLARUM_CORE_PATCHES=0
```

## Regression tests

```text
test/frontend-js-registration.test.mjs
test/frontend-js-registration.php
test/fixtures/flarum-1.8.19-Extend-Frontend.php
```

## Not in this tranche

- production companion pin update
- `/data/extensions/list` write
- cache clear / asset rebuild / PikaPods restart
- Follow Tags notification acceptance
- FORUM-SUB-001 family-follow
