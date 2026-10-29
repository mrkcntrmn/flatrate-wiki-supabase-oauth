# Flarum 2 member experience verification

Document class: **CANDIDATE_SOURCE_VERIFICATION_PROTOCOL**.
This document defines acceptance and promotion boundaries. Runtime results,
source HEADs, package references and asset hashes belong in the generated
evidence manifest and exact-head CI artifact. This is not production authority.

```text
WORK_ORDER=FORUM-F2-FULL-MEMBER-EXPERIENCE-QUAL-001
OUTCOME=Restore and qualify the established F2 member experience, including owner-required plain upvoting, and prepare final prelaunch evidence
BOUNDARIES=Isolated source changes, disposable databases and HTTP servers, synthetic identities, authenticated local browser and physical Pixel 5 tests, draft PR preparation
SOURCE_OF_TRUTH=Exact candidate sources, real Composer lock/install, actual Flarum resources/providers, migration ledgers, browser/physical-device evidence, current repository process authorities
DONE=Every required source criterion passes against the candidate; corrected extension ledger, source/lock/asset manifest, physical evidence and bounded deployment/recovery packet are available
CONSEQUENTIAL_BOUNDARY=No merge, production/provider changes, production migration, deployment, release publication or public reopening
```

## Changes under qualification

- Restore the established member identity field names and owner visibility.
- Register settings and dashboard extensions when F2 lazy route modules load.
- Correct the F2 discussion resource adapter and writable Tip marker field;
  the existing marker listener retains actor validation and canonical saving.
- Match the F2 NotificationSyncer return type when Follow Tags is enabled.
- Let enabled FoF Sitemap own robots.txt, retaining Navigation's reduced-stack
  fallback. This is in Navigation PR #101 and is pinned in the workflow.
- Provide plain upvotes with the supported F2 resource/schema interfaces.
  The owner explicitly required upvoting for launch. FoF Gamification remains
  retired; no points, ranks, downvotes, public voter lists or leaderboards return.

## Native upvote contract

`flatRateVote` accepts integer `1` or `0` through an existing post's update
resource. The independent `flatrate-voting.enabled` setting defaults closed.
The authenticated voter must hold `discussion.votePosts`, see both the post
and discussion, be unsuspended, and be a different user from the author.
Hidden and non-comment posts cannot receive ballots. Native fields expose
only counts, the viewer's own state and permission; no voter relationship is
carrying voter identities is registered. The admin extension page exposes the gate and permission.

Canonical ballots retain the historical `post_votes` table contract. A new
forward migration creates it only when absent and never rewrites existing
ballots. Rollback intentionally retains product data. The unique post/user
key and transaction-level post-row lock prevent duplicate concurrent casts.
Removing a vote retains its row identity with value zero. Positive visible
reply ballots contribute to whole-discussion totals and visible Brand totals.
The established card aggregate is preserved; Navigation continues removing
the redundant discussion-sidebar aggregate control.

The existing activity observer remains independently gated. Real Supabase
delivery is not exercised by this disposable qualification. Production
enablement of any activity/provider path is a separate operational decision.

## Required evidence

| Area | Evidence required |
| --- | --- |
| Package installation | Genuine Composer resolution/install and platform checks; no manually edited generated metadata or platform ignores |
| Identity | Signed provisioning, replay/collision/verification denials, ticket redemption, native admin recovery and closed member password/signup routes |
| Resource contracts | Owner/member/guest attributes, owner-only dashboard and retained nickname, correct discussion voting attributes |
| Profiles | Masquerade edit, validation, reload, required completion, actor permissions and explicit-include guest privacy |
| Discussions | Real formatting, create/edit persistence, author boundary and Tip marker persistence |
| Following/notifications | Actual providers and readable notifications with subject includes |
| Polls/reactions | Real creation/mutation, persistence and guest denials |
| Plain upvotes | Gate closed/open, idempotence, independent concurrent writers, aggregate/reload/removal, permission/self/guest/suspension/private/hidden denials, no voter disclosure |
| Uploads | Actual multipart handler, stored/downloaded image bytes, owner binding, opaque name, private/no-store and non-owner/guest denial |
| Crawl | Combined robots owner and fallback, same-origin sitemaps, one canonical, private content/profile exclusions and legacy redirects |
| Migrations | Both table prefixes; recorded historical no-op repair; field/answer identity preservation; ledger rerun; ballot preservation and uniqueness |
| Browser | Settings/dashboard/profile interactions, plain votes and keyboard removal, DM recipient/send/peer/outsider, admin controls, six widths, guest privacy, no page exceptions |
| Physical device | Pixel 5 settings/profile, touch upvote/removal/card total, actual keyboard and reachable 44px Send target, persisted DM, portrait/landscape, guest denial |

`test/f2-member-harness.mjs` reproduces the combined stack. It preserves every
external dependency's locked version/reference while refreshing local path
metadata. Synthetic credentials are stored with mode 0600 outside evidence;
they must never be committed or uploaded. The CI artifact uploads only the
sanitized evidence directory. Physical testing is separate from emulation.

The harness reports failures as failures. Browser scenario counts describe
the implemented checks, rather than a percentage of the entire application.
FoF Upload is verified using real local private storage; this does not replace
accepted R2-specific evidence or assert a fresh production R2 test.

## Extension ledger rules

The generated package manifest distinguishes installed from enabled. The
qualified enabled fixture includes the native language, tags, formatting,
nickname, moderation/support and subscription modules, plus Masquerade,
Follow Tags, Formatting, Polls, Reactions, FoF SEO, Sitemap, Upload, Extension
Manager and the custom identity, navigation, DM, Messaging and Media Privacy
extensions. Native Likes is not used as a second vote provider.

Live Chat is installed at its separately qualified source pin and remains
disabled in this combined fixture. Its earlier provider/realtime evidence
must retain its actual scope. Public Live release and revocation requirements
remain separate gates. Do not claim it passed this member-browser suite.

FoF OAuth, Gamification, custom Composer UI, discussion cards and public
counts retain their intentional retired/dark states. Wiki Context remains
source-only. Do not label drafts, merge/split, subscribed, user-directory,
follow-users or Advanced Extension Manager enabled/verified merely because
an earlier narrative listed them.

## Bounded production promotion packet

1. Freeze the accepted source commits, exact dependency lock, enabled list,
   migrations and compiled asset hashes. Require green exact-head CI. Review
   the complete stacked PR dependencies; this source is not merged yet.
2. Read the actual hosted package list, enabled extensions, database version,
   schema/ledger, asset identity and current rollout gates. Compare with the
   accepted manifest. Resolve drift before spending deployment authorization.
3. Capture a fresh provider backup and prove isolated recovery, including
   database, configuration and private uploaded bytes. Record snapshot ID,
   capture consistency policy, tested restore result and rollback target.
   An old backup authorization or exception is not blanket approval here.
4. Obtain bounded authorization for the exact production target and frozen
   candidate. Preserve the maintenance/reopening boundary during installation.
5. Apply only forward migrations and the reviewed dependency/asset update.
   For native voting, retain existing ballots, keep Gamification/Likes off,
   verify voter/privacy permissions, and then set the native gate according
   to the separately approved launch configuration.
6. Read back installed source references, lock/list digests, migration ledger
   and asset hashes. Exercise public paths and the authorized existing admin's
   read-only notifications, Messages, build identity and permission surfaces.
   Do not create synthetic production acceptance users.
7. Require final hosted mobile/desktop acceptance, private-storage evidence
   and any enabled Live/realtime acceptance. A local PASS is not a production
   PASS. Roll back to the recorded previous revision if acceptance fails;
   do not use destructive down migrations against product data.
8. Switch live only after the owner approves reopening and every applicable
   hosted criterion is proven. The source work order stops at this reviewable
   packet; it does not authorize performing these production steps.
