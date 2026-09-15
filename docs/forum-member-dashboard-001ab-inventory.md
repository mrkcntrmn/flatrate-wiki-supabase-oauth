# FORUM-MEMBER-DASHBOARD-001A/B — inventory and Phase 1 shell

Document class: **IMPLEMENTATION_NOTE**  
Status: **SOURCE / NOT AUTHORIZED FOR PRODUCTION MUTATION**  
Tranche: **FORUM-MEMBER-DASHBOARD-001A/B**

This extension (`flatrate/wiki-supabase-oauth`) owns the Phase 1 public/owner
profile shell. It does not become credential authority and does not add the
owner-profile bridge.

```text
OWNER_PROFILE_BRIDGE=false
PRIVATE_ONBOARDING_EDITOR=false
GARAGE_INTEGRATION=false
MERIT_PRODUCTION_UI=false
POINTS_PRODUCTION_UI=false
FOLLOWING=false
FOR_YOU=false
MEMBER_NUMBER_PUBLIC=true
MEMBER_NUMBER_OWNER_ONLY=false
MEMBER_NUMBER_OPT_IN=false
MEMBER_NUMBER_EDITABLE=false
FLARUM_CREDENTIAL_AUTHORITY=false
```

## Inventory (001A)

| Surface | Current owner | Phase 1 action |
| --- | --- | --- |
| `/u/<user>` public profile | Flarum core `UserPage` / `PostsUserPage` / `DiscussionsUserPage` / `UserCard` | Keep public posts/discussions; add public Member # |
| Profile tabs | Flarum Posts + Discussions; owner also Settings + Security (access tokens) | Keep; add owner Overview / Identity / Account & Security / Notifications |
| `/settings` | Flarum `SettingsPage` (extends `UserPage`): account, notifications, privacy | Preserve. Community identity editor remains here |
| Avatar / member menu | Flarum `UserCard` avatar editor; `SessionDropdown` Profile / Settings / Log out | Relabel Profile to **My Profile**; keep Settings |
| Member # | this extension: `users.id` via `flatRateMemberNumber` | Public on `UserCard`; not editable |
| Direct Message / Message | FoF Byobu / messaging-ui via `UserControls.userControls()` | Do not patch `UserControls` |
| Notification controls | Flarum Settings notification grid; header inbox is `/notifications` | Handoff to `/settings` |
| Profile indexing | FoF SEO `noindex, follow` + sitemap exclusion (`docs/forum-indexing.md`) | Unchanged |
| Mobile | Flarum `SelectDropdown` profile nav; this extension's brand drawer | Owner items join existing dropdown; stacked cards |
| Account & Security | `https://flatrate.wiki/account` (Supabase) | Handoff only; no inline email/phone/providers |
| Affiliated Brand | optional FoF Masquerade in `forum.js` | Unchanged |

Omitted future modules: Merit, Profile & Privacy editor, Brands & Live, Garage, Following, For You, Points.

## Shell (001B)

- Public attributes: `flatRateMemberNumber`, `flatRateMemberNickname`
- Owner-only attribute: `flatRateOwnerDashboard` (versioned allowlisted DTO) plus existing self-only nickname mode fields
- Client chrome renders only when that DTO is present on the profile user
- `/settings` and `https://flatrate.wiki/account` remain working destinations
