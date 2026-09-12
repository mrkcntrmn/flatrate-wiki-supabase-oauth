# FORUM-IDENTITY-002-R3 — Flarum 1.8 SPA boot

```text
FLARUM_CORE_VERSION_TESTED=1.8.19
ROOT_CAUSE=LAST_FRONTEND_JS_FILE_LEFT_MODULE_EXPORTS_UNDEFINED
FRONTEND_MODULE_RESOLUTION_MODEL=FLARUM_18_CORE_COMPAT_WITH_REG_FALLBACK
SOURCE_OF_TRUTH=js/dist/member-display.js
BUILD_OUTPUT=js/dist/member-display.js
DO_NOT_REWRITE_ALL_EXISTING_FRONTEND_ASSETS=true
```

## Production crash

PikaPods compiled each `Extend\Frontend('forum')->js()` file as:

```javascript
var module={};
<file>
flarum.extensions['flatrate-wiki-supabase-oauth']=module.exports;
```

`Application.tsx:344` then reads `extension.extend`. The last registered file
wins. `bbc9a62c` added `member-display.js` last and never assigned
`module.exports`, so the extension entry became `undefined` and the SPA failed
with:

```text
Cannot read properties of undefined (reading 'extend')
```

Existing assets already assigned `module.exports = {}`. Disabling only the
member-display Frontend extender (CASE_B) boots. The MariaDB migration was
not involved.

Flarum 1.8 `extend()` mutates an object method. It does **not** accept a
module-path string. SettingsPage is extended on `SettingsPage.prototype`.

Flarum 1.8.19 core does not expose `flarum.reg`. Official 1.8 extensions
(including `flarum/nicknames`) import through webpack externals that become
`flarum.core.compat['common/extend']` and
`flarum.core.compat['forum/components/SettingsPage']`. The candidate prefers
`flarum.reg.get('core', …)` when present, then those exact 1.8 compat keys.
`bbc9a62` probed `compat['extend']` / `compat['flarum/common/extend']`, which
are not the 1.8.19 keys.

Canonical webpack/source conversion was not introduced: the other three
forum assets remain hand-written IIFEs, and the production crash was the
missing CommonJS export, not a missing webpack pipeline.

## Future production rollout

```text
PRODUCTION_MEMBER_PROFILE_MIGRATION_ALREADY_APPLIED=true
PRODUCTION_MEMBER_PROFILE_TABLE=flarum_flatrate_member_profiles
CONTROL_322_PROFILE_ALREADY_EXISTS=true
```

Do not expect `CONTROL_PROFILE_EXISTED_PRE=false`. The next promotion must
prove the existing row stays correct and idempotent. Do not add a second
migration merely to reprint `Migrated`.

Do not redeploy `542d5a3` or `bbc9a62c`.
