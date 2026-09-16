# FORUM-TECH-CLUB-001 — Premium post badge

## Goal

Give active TECH CLUB premium members a visible identity marker on every forum post and reply:

`TECH CLUB 🧼`

Presentation contract:

- white lettering
- pink filled pill
- compact post-header placement
- visible on both discussion starters and replies
- no global `TagLabel` styling or native post-header layout mutation

## Entitlement seam

The first implementation treats membership in the Flarum group whose singular name is exactly `TECH CLUB` as the forum-side entitlement signal.

This keeps the rendering layer independent from billing. Today an administrator can add or remove the group in Flarum. Later the FlatRate.wiki account/billing entitlement pipeline can synchronize the same group membership without changing the post badge renderer.

Do not expose Stripe customer IDs, subscription IDs, price IDs, payment state, or other billing metadata to the forum client merely to render this badge.

## Runtime behavior

`js/dist/tech-club-badge.js` extends the shared `CommentPost.headerItems` surface. It resolves the post author, checks the author's loaded groups, and adds a `flatrateTechClubBadge` header item only when a group resolves to `TECH CLUB`.

Because Flarum uses `CommentPost` for discussion starters and normal replies, the same renderer covers both surfaces.

## Styling

`resources/less/tech-club-badge.less` owns the badge presentation and scopes layout changes to `.item-flatrateTechClubBadge` and `.FlatRateTechClubBadge` only.

The initial fill is `#d63384` with `#ffffff` text.

## Operator setup

1. In Flarum Admin, create or confirm a visible member group with singular name `TECH CLUB`.
2. Assign the group to the premium member.
3. Clear/rebuild Flarum assets as required by the deployment flow.
4. Confirm the member's discussion starter and reply each display `TECH CLUB 🧼`.
5. Remove the group and confirm the badge disappears after the user payload refreshes.

## Acceptance

- non-members never receive the badge
- TECH CLUB members receive the badge on both starters and replies
- the badge reads exactly `TECH CLUB 🧼`
- lettering is white and fill is pink
- Job Breakdown labels continue to render independently
- affiliated-brand layout remains unchanged
- no billing metadata is required by the browser

## Follow-up

When paid membership checkout is implemented, the authoritative account-side entitlement should add/remove the Flarum TECH CLUB group through the existing trusted identity/provisioning boundary. The visual contract should remain unchanged.
