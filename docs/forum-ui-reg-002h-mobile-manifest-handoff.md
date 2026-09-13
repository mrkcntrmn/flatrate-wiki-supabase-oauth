# FORUM-UI-REG-002H — Mobile drawer canonical IA handoff

Status: **SOURCE + DISPOSABLE QUALIFICATION**

Authority: **companion source** (`mrkcntrmn/flatrate-wiki-supabase-oauth`)

```text
PRODUCTION_DEPLOYMENT=false
NAV_ENABLE=false
MOBILE_DRAWER_COMPLEMENTARY=true
MOBILE_DRAWER_PRESERVED=true
```

## Intent

Keep the OAuth-owned HeaderSecondary mobile drawer enabled in both states,
but choose its presentation source from runtime capability:

```text
ABSENT flatrateForumNavigationManifest
  -> legacy FlatRateForumNavigation.resolve(app)

PRESENT + valid
  -> canonical dedicated-navigation manifest

PRESENT + invalid
  -> fail closed (do not add flatrateForumNavigationDrawer)
```

The serialized manifest is the capability signal. The drawer does not inspect
Flarum's extension manager.

## Canonical enabled-state IA

```text
Community          -> /community
Technician Topics  -> /t/general-shop-discussion
Brands             -> static 41-board tree from the manifest
```

Community and Technician Topics are direct links with no drawer children.
`Start Here` and General Live stay on `/community`. The durable tag name
`General Shop Discussion` is not a user-visible drawer label.

## Unchanged

```text
js/dist/forum-navigation.js
js/dist/forum-desktop-navigation.js
Extend\Conditional()->whenExtensionDisabled('flatrate-forum-navigation')
```

The shared contract remains the disabled-state fallback. The extracted
legacy desktop renderer remains gated as accepted in FORUM-UI-REG-002B.
