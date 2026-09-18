# FORUM-MEMBER-DASHBOARD-001H — H.0 inventory

Document class: **IMPLEMENTATION_NOTE / INVENTORY**  
Tranche: **FORUM-MEMBER-DASHBOARD-001H-COMMUNITY-ACTIVITY-MAP-R1**  
Status: **INVENTORY COMPLETE / IMPLEMENTATION BLOCKED ON TRUSTED STATE INGRESS**  
`PRODUCTION_MUTATION=false`

## SHAs inspected

| Repository | Ref | SHA |
| --- | --- | --- |
| `mrkcntrmn/flatrate-wiki-supabase-oauth` | `origin/main` (implementation base) | `f8bd1618e0dc1487b61e6f062b05029a3f951224` |
| `mrkcntrmn/flatrate-wiki` | `origin/main` | `254dc538054070ec8e4cd2b049e4f9820140e590` |
| Planning PR #355 branch | `docs/forum-member-dashboard-001h-community-activity-map` | fetched 2026-09-18 via owner terminal |

Planning authority read from owner-fetched copies:

```text
/tmp/forum-member-dashboard-001h-plan/forum-member-dashboard-001h-community-activity-map.md
/tmp/forum-member-dashboard-001h-plan/forum-member-dashboard-001-plan.md
/tmp/forum-member-dashboard-001h-plan/forum-member-dashboard-001-data-contract.md
```

PR: https://github.com/mrkcntrmn/flatrate-wiki/pull/355

Reconciled plan notes used for inventory:

- owner nav gains **Dashboard** (`fas fa-tachometer-alt`) ahead of Overview;
- Community Activity is Dashboard-first module, separate from `flatRateOwnerDashboard` DTO;
- stop at inventory if production lacks trusted state ingress (001H §10 / §22 001H.0 exit).

## Existing Phase 1 dashboard ownership (do not reopen)

| Surface | Owner | Notes |
| --- | --- | --- |
| Owner DTO | `src/Identity/OwnerDashboardDto.php` | schema_version=1; sections overview/identity/contributions/account_security/notifications; forbid list includes zip/live/activity_subject_id/merit/points |
| Serializer isolation | `src/Api/SerializeMemberProfile.php` | public Member # / nickname; `flatRateOwnerDashboard` only when actor id matches profile user id |
| Client chrome | `js/dist/member-dashboard.js` | **authoritative browser asset** (no separate `js/src` tree); initializer `flatrate-wiki-member-dashboard` |
| Styles | `resources/less/forum.less` | `.FlatRateOwnerDashboard*` |
| Locale | `resources/locale/en.yml` → `flatrate-dashboard.forum.*` |
| Inventory note | `docs/forum-member-dashboard-001ab-inventory.md` | Phase 1 A/B accepted |
| Tests | `test/forum-member-dashboard-001.test.mjs`, `test/member-dashboard-initializer.test.mjs`, `test/owner-dashboard-dto.php` | |

### Route / nav reality today

There is **no** dedicated `/u/<user>/dashboard` child route yet.

Current owner chrome:

- extends `UserPage.prototype.navItems` with Overview / Identity / Account & Security / Notifications when DTO present;
- Overview content is injected by **overriding `PostsUserPage.prototype.content`** on the posts profile page;
- Overview icon is `fas fa-th-large`, not tachometer;
- visibility is DTO-gated (server owner match), not `app.session.user === this.user` alone.

001H must **add** an owner-only Dashboard entry (`fas fa-tachometer-alt`) without rewriting accepted Overview/Identity/Settings/Account handoff behavior.

## Frontend registration / build

Flarum 1.8 `Frontend::js()` is a scalar overwrite. Current unconditional forum JS paths (must remain five unless registration tests are deliberately updated):

```text
js/dist/forum-navigation.js
js/dist/forum.js
js/dist/mobile-brand-drawer.js
js/dist/member-display.js
js/dist/member-dashboard.js
```

Plus conditional sixth: `js/dist/forum-desktop-navigation.js` when `flatrate-forum-navigation` is disabled.

**Authoritative JS practice:** edit `js/dist/member-dashboard.js` in place (hand-maintained dist; no webpack/source split in this package). Prefer extending that bundle for Community Activity UI rather than adding a sixth unconditional `->js()`.

Regression gates:

- `test/frontend-js-registration.test.mjs`
- `test/frontend-js-registration.php`

## API / route conventions

Existing API routes in `extend.php`:

```text
POST  /api/flatrate-sso/provision
POST  /api/flatrate-sso/ticket
PATCH /api/flatrate/member-display
```

Forum route:

```text
GET /auth/flatrate/session
```

Controllers take `ServerRequestInterface`, use `RequestUtil::getActor($request)`, ignore client-supplied identity fields (see `MemberDisplayController` unsetting `member_number`).

001H target semantic routes (names adaptable):

```text
POST /api/flatrate/presence/touch
GET  /api/flatrate/dashboard/community-activity
```

Do **not** put presence into `flatRateOwnerDashboard` / public user attributes.

## DB / migration conventions

Migrations use `Flarum\Database\Migration::createTable(...)` with logical names such as `flatrate_member_profiles`. Prefix is applied by Illuminate/Flarum (`$connection->getTablePrefix()`); migrations must not hard-code unprefixed production names.

Suggested logical table (subject to implementation):

```text
flatrate_member_presence
  subject_key   (unique / PK)
  state_code
  last_seen_at
```

Indexes needed for window queries: `last_seen_at`, `(state_code, last_seen_at)`.

MariaDB disposable harness: `test/harness/mariadb-migration` + `test/member-profile-mariadb-migration.php`.

## Product Activity separation

Activity subsystem (`src/Activity/*`) emits to outbox / HMAC ingest. Presence must **not** call emitters, alter metric versions, or reuse activity subject IDs as the stored presence key without an explicit review.

Candidate presence subject scheme (design only until H.1):

```text
HMAC-SHA256(
  canonical_internal_member_identity,
  dedicated_presence_secret
)
```

Canonical identity candidates already in tree: Flarum `users.id` and/or Supabase sub via `FlatRateSubjectResolver` (login_providers provider=`flatrate`). Presence secret should be dedicated (not reuse SSO shared secret without review).

## Map asset choice (provisional)

Prefer local SVG + state centroids / inset AK+HI inside the dashboard bundle or a small static asset under `resources/` / `js/dist/`. No Google Maps / Mapbox / analytics SDK unless a later audit proves necessity.

## Test commands (from `.github/workflows/ci.yml`)

```text
node --test test/*.test.mjs
composer validate --strict --no-check-publish
php test/owner-dashboard-dto.php
php test/frontend-js-registration.php
php test/member-profile-mariadb-migration.php   # with MariaDB service
bash test/harness/flarum-spa-1.8.19/bin/bootstrap.sh
npx playwright test                              # under test/harness/flarum-spa-1.8.19
find . -name '*.php' ... | xargs -0 -n1 php -l
```

## TRUSTED_STATE_SOURCE — inventory finding (BLOCKS H.1 state ingestion)

### What production architecture shows

- `forum.flatrate.wiki` is Cloudflare-proxied Full (strict) to PikaPods (`docs/forum-operations.md`).
- Public response evidence shows Cloudflare + Caddy in path (`server: cloudflare`, `via: 1.1 Caddy`).
- Repository contains **no** evidence that Cloudflare Managed Transform **Add visitor location headers** is enabled for the forum zone.
- Repository contains **no** captured origin-bound request header dump proving `cf-region-code` reaches PHP/Flarum.

### What Cloudflare can provide (vendor docs)

With IP Geolocation alone, origin typically receives **country**:

```text
CF-IPCountry
```

State-level region code is part of Managed Transform **Add visitor location headers**, which adds (among others):

```text
cf-ipcountry
cf-region
cf-region-code
cf-ipcity
cf-iplongitude
cf-iplatitude
cf-postal-code
...
```

Reference: Cloudflare Rules → Managed Transforms reference (`add_visitor_location_headers`).

### Inventory conclusion

```text
TRUSTED_STATE_SOURCE=UNPROVEN
BROWSER_GEOLOCATION=false (required; must remain false)
CLIENT_SUPPLIED_STATE_TRUSTED=false (required)
```

Per 001H authority: **do not implement state ingestion** until a reviewed trusted ingress signal exists. Do not fall back to GPS, client POST body state, or IP retention.

### Smallest privacy-safe infrastructure change required (not authorized here)

Prefer a **narrow** edge change over enabling the full visitor-location Managed Transform:

1. **Preferred:** Cloudflare Transform Rule / Snippet that sets only:
   - `CF-IPCountry` (if not already present from IP Geolocation)
   - `CF-Region-Code` (USPS-style region code when available)
   and does **not** forward city / lat / lng / postal code headers to origin.
2. **Acceptable fallback:** enable Managed Transform **Add visitor location headers**, then ensure the Flarum presence code **reads only** allowlisted `cf-region-code` when `cf-ipcountry=US`, and **never stores** city/lat/lng/ZIP/IP.
3. Prove end-to-end that Caddy on PikaPods forwards the chosen header(s) into PHP `$_SERVER` / PSR-7 request headers (name may be `CF-Region-Code` / `HTTP_CF_REGION_CODE`).
4. Only after that proof: set

```text
TRUSTED_STATE_SOURCE=cloudflare_cf-region-code_header_via_<exact_rule_id_or_managed_transform>
```

`CLOUDFLARE_PRODUCTION_MUTATION=false` under this work order — the edge change needs a separate owner authorization.

## Cleanup / retention (design)

```text
PRESENCE_RETENTION_MAX=25_HOURS
```

Cleanup candidates once storage exists: Flarum scheduled console command (pattern already used by `DrainActivityOutboxCommand`) and/or opportunistic prune on touch/aggregate.

## Aggregate cache (design)

```text
SERVER_AGGREGATE_CACHE_MAX≈60_SECONDS
CLIENT_REFRESH=60_SECONDS
CLIENT_HEARTBEAT_INTERVAL=5_MINUTES
SERVER_MIN_TOUCH_INTERVAL=5_MINUTES
MIN_VISIBLE_STATE_COHORT=3
```

Cache only privacy-filtered aggregates (never raw subject lists).

## Feature gate (design)

Prefer a Flarum setting defaulting off or on for source qualification only, e.g. `flatrate-presence.enabled`, so Community Activity UI + touch can disable without dropping the Phase 1 dashboard shell.

## Blockers before H.1+

1. **Trusted state ingress unproven (BLOCKS 001H.0 exit `TRUSTED_STATE_SOURCE_IDENTIFIED`):** no evidence `cf-region-code` reaches Flarum/PHP today. Per 001H §10, stop state ingestion until a reviewed trusted path exists. Prefer a narrow Transform Rule/Snippet that forwards only `CF-IPCountry` + `CF-Region-Code` (not city/lat/lng/ZIP). Edge change needs separate owner authorization (`CLOUDFLARE_PRODUCTION_MUTATION=false` under this work order).
2. **Agent GitHub network:** Cursor agent sandbox still blocks CONNECT to `api.github.com` even with an exported token; owner-terminal `gh`/`git` remain the write path for fetches/PRs until that is fixed.

## Non-blockers (ready once trusted state is identified)

- Planning authority for 001H / reconciled 001 plan + data-contract is now readable.
- Owner-only Dashboard child route / nav item design against existing UserPage navItems.
- Dual-layer SVG map + shared `radius = K * sqrt(count)` + decorative LIVE halo.
- Server aggregate + k=3 suppression + retention cleanup with injectable/frozen clocks.
- Presence touch controller that ignores body userId/state/lat/lng once trusted state exists.
- Extending `js/dist/member-dashboard.js` without adding a sixth unconditional JS registration.

## 001H.0 exit checklist

```text
DASHBOARD_H_ROUTE_OWNER_IDENTIFIED=true
  -> flatrate/wiki-supabase-oauth; UserPage navItems + new profile child route;
     Community Activity stays out of OwnerDashboardDto

PRESENCE_STORAGE_OWNER_IDENTIFIED=true
  -> same extension; new flatrate_member_presence migration + store

MAP_RENDERING_PATH_IDENTIFIED=true
  -> extend js/dist/member-dashboard.js + resources/less/forum.less;
     local SVG/centroids; no third-party map SDK

TRUSTED_STATE_SOURCE_IDENTIFIED=false
  -> candidate cf-region-code via Cloudflare; NOT proven at origin

NO_PRODUCTION_MUTATION=true
```

## Next owner decisions needed

1. Authorize (or reject) the smallest Cloudflare change to deliver **only** trusted US `region-code` to the Flarum origin, then prove header presence at PHP (name may be `CF-Region-Code` / `HTTP_CF_REGION_CODE` after Caddy).
2. After (1), resume H.1 server privacy/data layer on branch `feat/forum-member-dashboard-001h-community-activity-map` @ `f8bd161…`.
3. Optional: fix agent outbound GitHub access, or continue using owner-terminal for `git push` / `gh pr create`.
