# FORUM-PUBLIC-PSEUDONYM-001A — guest identity surface inventory

Document class: **IMPLEMENTATION_EVIDENCE**  
Tranche: **001A**  
Status: **SOURCE / NOT PRODUCTION**  
Base: oauth `f233d81` → branch `feat/forum-public-pseudonym-001b`  
Planning authority: wiki PR #309 (`7ea689ed…`, OPEN, not merged)

## Frozen slug finding

```text
ACTIVE_USER_SLUG_DRIVER=Flarum\User\UsernameSlugDriver (default for User)
SLUG_SOURCE=users.username
GUEST_AUTH_PROFILE_CANONICAL_ROUTE_DIVERGENCE=false
PROFILE_ROUTE_REGRESSION=PASS (in-process harness)
MENTION_CONTENT_HTML_GUEST=PASS (in-process harness)
MENTION_CONTENT_HTML_AUTH=PASS (in-process harness)
VIEWER_CONTEXT_REENTRANT=PASS
NO_HTTP_CONTEXT_DELEGATION=PASS
```

`UsernameSlugDriver::toSlug()` returns `$instance->username`, not `display_name`. Guest projection of display names therefore does not create viewer-dependent profile URLs. Durable route remains `/u/<username>` (e.g. `/u/tech_a84f19c2`).

## Display-name driver lifecycle (Flarum 1.8.19)

```text
container singleton flarum.user.display_name.driver
  <- settings display_name_driver (nickname via flarum/nicknames)
UserServiceProvider::boot
  -> User::setDisplayNameDriver(make(driver))
```

FlatRate decorates that singleton via `container->extend` + re-`setDisplayNameDriver` in extension `ServiceProvider::boot`. Viewer state is **not** read from the container request; it comes only from `ViewerIdentityContext` push/pop middleware.

## Surface matrix (observed field names)

| SURFACE | GUEST CURRENT | TARGET | OWNER |
| --- | --- | --- | --- |
| API `BasicUserSerializer.username` | `tech_<8hex>` | unchanged | core |
| API `BasicUserSerializer.displayName` | nickname / `tech_#N` | `tech_<8hex>` username | GuestAwareDisplayNameDriver + SerializeGuestPublicIdentity |
| API `BasicUserSerializer.avatarUrl` | custom URL if set | `null` (neutral letter avatar) | SerializeGuestPublicIdentity |
| API `BasicUserSerializer.slug` | username | unchanged | UsernameSlugDriver |
| API `flatRateMemberNumber` | exposed to guests | omitted | SerializeMemberProfile guest gate |
| API `flatRateMemberNickname` | exposed to guests | omitted | SerializeMemberProfile guest gate |
| API owner DTO / nickname mode | omitted for non-owner | still omitted for guests | SerializeMemberProfile |
| Post/reply author (SPA) | `displayName` from include | username alias | same serializers |
| Likes relationship users | `BasicUserSerializer` | guest projection | same |
| Mentions `contentHtml` `@displayname` | `$user->display_name` at render | username when guest context active | GuestAwareDisplayNameDriver |
| Post-mention displayname | `$post->user->display_name` | username when guest | GuestAwareDisplayNameDriver |
| SSR profile `<title>` | API `displayName` | username | Forum\Content\User via API |
| Boot `payload.resources` users | includes nickname display | guest-safe attrs | serializers + middleware |
| Member dashboard UserCard Member # | reads `flatRateMemberNumber` | hidden (attrs absent) | SerializeMemberProfile + JS |
| Authenticated stranger Member # | public | **unchanged** | SerializeMemberProfile |
| Owner settings / dashboard | current | **unchanged** | no change |
| DM / Live | authenticated surfaces | **unchanged** | out of scope |
| User-authored prose | may self-identify | not rewritten | N/A |

## Username reliability

New linked users: `NeutralIdentity::handle($sub)` → `tech_<8hex>`. Migration rewrote flatrate-linked email usernames when collision-free. Collision leftovers / admin recovery accounts may not match `tech_[0-9a-f]{8}`; guest alias is still whatever `users.username` is. Production reconciliation of leftovers is out of scope (`USERNAME_RENAME=false`).

## Cache

Application serializers are request/actor scoped. No Cloudflare changes in this tranche. Cross-view edge cache isolation: `RUNTIME_ACCEPTANCE_REQUIRED` for production.

## Historical mentions

```text
POST_CONTENT_MIGRATION=false
MENTION_BACKFILL=false
```

Stored mention XML may retain historical presentation attributes; live guest `contentHtml` rendering must overwrite platform-generated mention `displayname` via the decorated display-name driver under guest context.

## 001A exit

```text
GUEST_IDENTITY_SURFACES_ENUMERATED=true
IMPLEMENTATION_HOOKS_IDENTIFIED=true
ACTIVE_USER_SLUG_DRIVER=UsernameSlugDriver
```
