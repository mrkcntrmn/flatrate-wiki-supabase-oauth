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

Register each JS source through its own `Extend\Frontend('forum')` instance
(one JS path each). The shared contract, main forum bundle, mobile drawer,
and member display stay unconditional. Only the extracted legacy desktop
IndexPage sidebar bundle is gated:

1. `js/dist/forum-navigation.js` — `FlatRateForumNavigation` contract (unconditional)
2. `js/dist/forum.js` — login / Job Breakdown / Affiliated Brand (unconditional)
3. `js/dist/forum-desktop-navigation.js` — legacy IndexPage sidebar, loaded only while `flatrate-forum-navigation` is disabled
4. `js/dist/mobile-brand-drawer.js` — mobile drawer initializer (unconditional)
5. `js/dist/member-display.js` — member display (unconditional)

Both LESS files remain on the first extender (`css()` appends safely):

```text
resources/less/forum.less
resources/less/mobile-brand-drawer.less
```

```text
UNCONDITIONAL_FORUM_JS_COUNT=4
CONDITIONAL_LEGACY_DESKTOP_JS=1
MAX_JS_CALLS_PER_EXTENDER=1
SHARED_NAV_CONTRACT_GUARDED=false
MOBILE_DRAWER_GUARDED=false
MAIN_FORUM_BUNDLE_GUARDED=false
IA013_JS_SOURCE_CHANGED=false
FLARUM_CORE_PATCHES=0
```

## Regression tests

```text
test/frontend-js-registration.test.mjs
test/frontend-js-registration.php
test/forum-desktop-navigation-handoff.php
test/fixtures/flarum-1.8.19-Extend-Frontend.php
test/fixtures/flarum-1.8.19-Extend-Conditional.php
```

## Not in this tranche

- production companion pin update
- `/data/extensions/list` write
- cache clear / asset rebuild / PikaPods restart
- Follow Tags notification acceptance
- FORUM-SUB-001 family-follow
