# FlatRate Wiki Supabase OAuth

Flarum 1.8 extension for FlatRate Wiki identity integration and forum metadata.

Supabase Auth remains the canonical credential/account authority. Flarum keeps only local community identity/state linked to the immutable Supabase/OIDC `sub`.

The production target has two complementary paths:

1. **Primary product path:** FlatRate.wiki provisions the linked Flarum identity server-to-server and enters Community with a short-lived, opaque, one-time session ticket. Users do not see an OAuth consent/callback flow when they click Community.
2. **Rollback/fallback path:** the existing OAuth 2.1 authorization-code flow with PKCE `S256` remains available during migration and for explicit legacy-account linking.

## Security and identity contract

- Supabase `sub` is the only cross-system identity key.
- Email is a private attribute and is never used to infer or auto-link an unrelated Flarum account.
- Flarum routing usernames are deterministic opaque handles: `tech_<stable-hash(sub)>`.
- Flarum Nicknames is the user-editable public display-name layer.
- New forum identities require a non-empty verified email attribute, but linkage remains keyed to `sub`.
- If a verified email is already owned by an unrelated local Flarum account, provisioning fails closed with `existing_account_requires_explicit_link`.
- Ordinary native Flarum password login/signup are blocked server-side; native administrator password login remains an unadvertised recovery path.
- FlatRate.wiki and Flarum do **not** share authentication cookies.
- Supabase access tokens, refresh tokens, passwords, and service-role credentials never appear in forum-entry URLs.
- Internal bridge requests require a deployment-only HMAC secret, timestamp, and nonce.
- Forum-entry tickets are cryptographically random, hashed at rest, expire after 45 seconds, and are atomically single-use.

### Identity fields

| Concern | Source of truth | Example | Public? |
| --- | --- | --- | --- |
| Authentication identity | Supabase `sub` | UUID-like subject | No |
| Login/account address | Supabase/Flarum email | `tech@example.com` | No |
| Flarum routing username | Derived from `sub` | `tech_a1b2c3d4` | Yes |
| Display name / nickname | Flarum Nicknames | `EV Tech` | Yes |

## Requirements

- PHP `>=8.1`
- Flarum `^1.8.1`
- `flarum/nicknames:^1.8.3`
- `fof/oauth:^1.7.4`
- `fof/extend:^1.3.4`
- `league/oauth2-client:^2.7`

## Install

```bash
composer require flatrate/wiki-supabase-oauth:^0.2
```

For the managed PikaPods/Flarum image, persist the package in `/data/extensions/list` so it is restored after restart.

Enable dependencies in this order:

1. **Nicknames**
2. **FoF OAuth**
3. **FlatRate Wiki Login**

## Seamless product-to-forum flow

The normal user journey is intentionally not a browser OAuth flow:

```text
FlatRate.wiki signup / confirmation
  -> authenticated Supabase user
  -> POST forum /api/flatrate-sso/provision (server-to-server)
  -> Flarum user + flatrate LoginProvider keyed by sub

User clicks Community
  -> FlatRate.wiki verifies/refreshes its Supabase session
  -> POST forum /api/flatrate-sso/ticket (server-to-server)
  -> short-lived opaque one-time ticket
  -> browser GET forum /auth/flatrate/session?ticket=<opaque>
  -> Flarum consumes ticket atomically
  -> Flarum issues its own normal remember/session cookie
  -> redirect directly to requested Community path
```

The single top-level request to `forum.flatrate.wiki` is necessary so the forum can issue its own host-scoped cookie. There is no second password, popup, consent page, authorization-code callback page, or shared parent-domain cookie.

## Internal bridge configuration

Set the same high-entropy deployment secret on both the FlatRate.wiki server and the Flarum/PikaPods runtime.

Environment configuration is preferred when the host exposes arbitrary environment variables:

```text
FORUM_SSO_SHARED_SECRET=<at-least-32-random-characters>
```

On a managed host that does not expose that environment variable, open the **FlatRate Wiki** provider settings under FoF OAuth and enter the same value in **Community SSO Shared Secret**. The extension reads the environment variable first and otherwise falls back to the private FlatRate provider setting `fof-oauth.flatrate.sso_shared_secret`.

The provider setting is intended only as a managed-host deployment fallback. Do not reuse the OAuth client secret, and do not commit either secret to GitHub or public Flarum assets.

Internal requests use these headers:

```text
X-FlatRate-Timestamp: <unix-seconds>
X-FlatRate-Nonce: <random-base64url-or-hex>
X-FlatRate-Signature: v1=<hex-hmac-sha256>
```

Canonical signing input:

```text
<timestamp>\n
<nonce>\n
<METHOD>\n
<request-path>\n
<sha256(raw-request-body)>
```

The receiver rejects stale timestamps, malformed signatures, and duplicate nonces. Nonce hashes are stored only long enough to enforce replay protection.

## Internal endpoints

### `POST /api/flatrate-sso/provision`

Authenticated server-to-server only.

Request body:

```json
{
  "sub": "immutable-supabase-sub",
  "email": "private@example.com",
  "email_verified": true
}
```

Behavior is idempotent:

- return the user already linked by `login_providers(provider=flatrate, identifier=sub)`; or
- create exactly one Flarum user with deterministic routing username and neutral nickname;
- create the `flatrate` provider link keyed to `sub`;
- never join an unrelated account solely because email matches.

The provisioner uses Flarum's own `RegistrationToken` + `RegisterUserHandler` path so core validation/events, nickname persistence, email activation, and provider-link persistence remain intact.

### `POST /api/flatrate-sso/ticket`

Authenticated server-to-server only. It accepts the same identity fields plus a relative `return_to` path. It idempotently ensures the linked forum user exists and returns an opaque entry path with a 45-second TTL.

Example response shape:

```json
{
  "ok": true,
  "entry_path": "/auth/flatrate/session?ticket=<opaque>",
  "expires_in": 45
}
```

### `GET /auth/flatrate/session?ticket=<opaque>`

Browser entry endpoint. It:

1. hashes and looks up the ticket;
2. locks the row and confirms it is unexpired/unconsumed;
3. marks it consumed in the same transaction;
4. creates Flarum's normal `RememberAccessToken`;
5. sets the normal Flarum remember cookie;
6. redirects to the ticket-bound relative forum path.

Responses use `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. Tickets contain no email, JWT, refresh token, or other PII.

## Signup and self-healing provisioning

FlatRate.wiki should call `/provision` whenever a Supabase account becomes verified/authenticated:

- immediately after signup if Supabase returns a session;
- after email-confirmation verification;
- after accepting a confirmed callback session;
- on ordinary login as an idempotent repair path.

Community entry should call `/ticket`, which also runs the same idempotent provisioner. This means a missed signup webhook/callback cannot permanently strand the account.

## Existing accounts

Existing `login_providers(provider=flatrate, identifier=sub)` rows created by the OAuth flow are reused unchanged by the new bridge. No migration to a new identity key is required.

Do not automatically link an existing Flarum-native account because its email matches a Supabase account. Use explicit linking for legacy accounts.

## OAuth rollback/fallback path

The OAuth provider remains configured during rollout. It still uses:

```text
/auth/v1/oauth/authorize
/auth/v1/oauth/token
/auth/v1/oauth/userinfo
```

with scopes:

```text
openid email profile
```

and requires:

```text
response_type=code
code_challenge=<non-empty value>
code_challenge_method=S256
```

The Flarum callback remains:

```text
https://forum.flatrate.wiki/auth/flatrate
```

The Supabase OAuth client is confidential and uses `client_secret_post`.

A new identity arriving through this fallback path delegates to the same reusable `FlatRateUserProvisioner`, so OAuth and the ticket bridge cannot create divergent forum identities.

### Explicit legacy account linking

While signed into the target native Flarum account, use:

```text
https://forum.flatrate.wiki/auth/flatrate?linkTo=<FLARUM_USER_ID>
```

FoF OAuth verifies that the authenticated actor matches `linkTo` before creating the provider record. Keep this primarily for migration/recovery; ordinary product navigation should use the seamless ticket bridge.

## Public-auth behavior

The extension:

- hides public native username/password login controls;
- hides public signup and forgot-password affordances;
- hides local Change Password / Change Email controls for ordinary users;
- exposes Nicknames for public identity management;
- rejects ordinary native password authentication server-side;
- rejects native public user creation without an OAuth registration token;
- preserves native administrator password login for recovery.

## Reply Job Breakdown marker

Reply classification is stored as FlatRate-owned post metadata because Flarum tags are discussion-level relationships. The extension does not attach native Flarum tags to individual posts.

When marked, the reply reuses the existing Flarum **Job Breakdown** secondary tag's TagLabel presentation (name, color, icon) without modifying the discussion's tag relationship.

- The `flatrate_post_markers` table stores the controlled `job-breakdown` marker by post ID.
- API post payloads expose the marker as `attributes.flatRateJobBreakdown`.
- Reply and edit composers show a compact **Job Breakdown** checkbox for replies.
- Marked replies resolve the canonical Flarum tag by slug `job-breakdown` and render Flarum's own `tags/helpers/tagLabel` output in the post header.
- Discussion starters are not valid marker targets, and the backend fails closed if a request tries to mark one.
- Deleting a post deletes its local marker rows.
- Marking a reply never adds or removes `discussion.tags()`.

## Optional Affiliated Brand presentation

When **FoF Masquerade** is installed and enabled, the forum bundle can render one optional self-declared profile value directly beneath the author's username in discussion posts and replies.

- Masquerade field name: `Affiliated Brand` (Dropdown / `select`, optional).
- The renderer resolves the unique active Masquerade field by exact name and type from the already-loaded `masquerade-field` store; it does not hardcode production field IDs.
- User answers are read from the loaded `user.masqueradeAnswers()` relationship; the bundle does not issue per-post API requests.
- Presentation is plain text (`span.FlatRateAffiliatedBrand`), not a TagLabel, badge, or OEM logo.
- Blank or missing values render nothing (no spacer line).
- Masquerade is optional at runtime: if the extension or field is absent, SSO and other forum behavior continue unchanged.

This value is self-declared profile metadata only. It does not indicate employment, certification, dealership status, or OEM verification; it is not mirrored to Supabase; and it does not mutate discussion vehicle-make tags or Job Breakdown metadata.

FoF Masquerade stores dropdown option lists in `fof_masquerade_fields.validation` as a comma-separated `in:` rule. The upstream default column is `VARCHAR(255)`, which truncates long brand lists. This extension widens that column to `TEXT` when Masquerade is present so the full Affiliated Brand vocabulary can be saved.

## Production proof gate

Before removing the OAuth product path, verify:

- a new confirmed FlatRate.wiki account creates exactly one linked Flarum identity without opening the forum;
- repeat provisioning creates no duplicates;
- existing linked users resolve the same Flarum row;
- clicking Community lands already authenticated at the requested forum path;
- clicking Community Settings lands at `/settings` already authenticated;
- reused, expired, malformed, and tampered tickets fail closed;
- replayed/stale HMAC requests fail closed;
- changing the Supabase email does not create a second forum identity;
- Flarum bans/suspensions still apply;
- PikaPods restart restores the extension and migrations;
- reply Job Breakdown markers can be created, edited, rendered, and deleted without changing forum tags;
- no bridge secret, Supabase token, password, or PII appears in browser URLs, logs, GitHub, or public assets.

## Development

Run static contract tests:

```bash
node --test test/*.test.mjs
```

Run PHP syntax validation:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## License

MIT.
