# Flarum 2.0.2 identity canary

Source pin: `6c7b0b4e10aeeef4edf2dcf441f209fe28f2e7b1`.

This branch is a disposable identity and ticket bridge. It is not a port of the Flarum 1 extension, and it is not a production release.

## Registration

Flarum 2 removed `Flarum\User\Command\RegisterUserHandler`. New users are created by saving a `Flarum\User\RegistrationToken` for provider `flatrate` and posting that token to the core user resource as an administrator. Core applies the token attributes, dispatches `RegisteringFromProvider`, and writes `login_providers` in `UserResource::fulfillToken`. Public signup is not enabled. The HTTP routes reject requests that fail HMAC authentication.

Identity remains `login_providers.provider = flatrate` and `identifier = subject`. Email, nickname, and display name are not identity keys. An email already owned by another user returns `existing_account_requires_explicit_link` and does not merge.

## Preserved ticket and request checks

- HMAC-SHA256 over `timestamp`, `nonce`, method, the application path, and the SHA-256 of the raw body. Flarum presents `/api/flatrate-sso/provision` to the authenticator as `/flatrate-sso/provision`.
- Timestamp skew of 60 seconds and a database primary key on the nonce hash.
- Ticket lifetime of 45 seconds, stored as a hash, consumed in a transaction.
- Return targets must be a single-origin relative path. Protocol-relative and absolute URLs are rejected.
- A shared secret shorter than 32 characters fails closed.
- Suspended users do not receive a session cookie.

## Not booted

These Flarum 1 capabilities stay in the tree and are excluded from `extend.php` and from `migrations/`:

- FoF OAuth provider registration and `fof/extend`.
- Nicknames, tags, member profiles, and public nickname settings.
- Post markers, voting readiness, and voter relationships.
- Activity outbox, scheduler, and beta projection.
- Admin gamification and member-display routes.
- Forum and API identity middleware that previously gated ordinary browsing.

The server-side gate `RequireFlatRateIdentity` is registered. It rejects ordinary password login and native signup. An administrator can still use password recovery. It does not hide the forum from guests.

Unqualified routes such as `/api/flatrate-voting/readiness` are not registered.
