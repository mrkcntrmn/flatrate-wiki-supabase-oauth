# F2 guest identity regression candidate

Status: **IMPLEMENTED / local and CI qualification required; not deployed**

The installed F2 projection passed through stored usernames. An account with a
legacy or custom username could therefore expose that name in guest author
labels, API resources, profile links, mentions and SEO output. Existing tests
covered already-neutral usernames and did not establish the legacy case.

This candidate stacks on the installed member-restoration source. It changes
presentation, not account storage, provisioning, external account links,
authenticated display names, permissions or rollout settings.

## Projection contract

- Preserve a stored username only when it exactly matches `tech_[0-9a-f]{8}`.
- Otherwise render `tech_` plus the first eight SHA-256 hex characters of
  `flatrate:legacy-public-user:<Flarum row id>`. This is a deterministic legacy
  fallback, not a second persisted alias allocator. It uses no email, nickname,
  external subject or secret. The Flarum row id is already public in JSON:API;
  this hash is not an anonymity or anti-correlation guarantee.
- An unsaved/nonpositive-id model renders the neutral `tech_00000000` sentinel.
  It is not a persisted account identity.
- Preserve neutral username routes. Emit `<row-id>-<fallback-alias>` for a
  non-neutral user's guest profile link and resolve it through the native
  actor-visible repository. Exact alias validation prevents an arbitrary suffix
  from resolving as a valid fallback. Original username URLs remain compatible.
- Apply the same projection to display-name rendering, JSON:API username,
  display name and slug, legacy serializer attributes, and the FoF SEO 4 profile preparation event.
  The latter otherwise bypasses display-name projection with raw username/avatar
  reads; guest profile metadata omits the custom avatar and raw profile bio. Keep authenticated
  display and selected slug-driver settings unchanged.

The existing eight-hex namespace is retained. No uniqueness guarantee is added;
legacy profile routes include the row id and therefore do not require reverse
hash lookup, a new table or a table scan. A wider alias namespace would be a
separate compatibility decision.

## Acceptance

`php test/f2-guest-identity-unit.php` executes the actual projection and driver
classes with lightweight Flarum interfaces. It covers malformed names, neutral
compatibility, rename stability, nested viewer context, unchanged authenticated
presentation, profile resolution, wrong-alias rejection and actor propagation.

`FLARUM_ROOT=/tmp/<synthetic-app> php test/f2-guest-identity-boot.php` requires a
real disposable Flarum 2 app, MariaDB and the actual SEO extension. It tests API
identity fields and guest/auth/guest isolation, avatars/member metadata,
formatter mentions, public discussion/profile HTML, boot/SEO output, legacy
profile compatibility and unchanged stored users. The existing combined-stack
CI harness runs it alongside member, voting, media and browser regressions.

Public historical username URLs and already-disclosed content/cache copies are
not erased. Literal self-disclosure in user-authored posts is not rewritten.
A successful local/CI result is source qualification only; merge, installation,
asset/cache rebuild and bounded hosted acceptance remain separate actions.
