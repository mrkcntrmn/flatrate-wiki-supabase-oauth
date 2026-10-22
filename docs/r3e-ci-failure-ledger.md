# R3E CI failure ledger

Classified from extension run `39724979948` at head `ca423dc9783193c351ac36277ab38ac3ecdf708d`.
Recorded 2026-10-22T05:02:00Z. That run remains FAIL. This follow-up does not rewrite it.

The F1 pin `6c7b0b4e10aeeef4edf2dcf441f209fe28f2e7b1` has the same SPA bootstrap script. `composer create-project` runs before that script writes `policy.advisories.ignore-id`, so the two Flysystem advisories block the F1 install on the pin and on the canary. That is a baseline harness failure, not an F2 identity regression.

## Job failures

| TEST_NAME | JOB | FILE_AND_LINE | FAILURE_REASON | F1_BASELINE_REPRODUCES | F2_CANARY_EXPECTED | CANDIDATE_REGRESSION | REPAIR_DISPOSITION |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Bootstrap disposable Flarum 1.8.19 | flarum-spa-boot | test/harness/flarum-spa-1.8.19/bin/bootstrap.sh:30 | Composer refuses `league/flysystem` 1.x for `PKSA-w9tt-7782-78jx` and `PKSA-pwh8-d4fr-nywn` before the harness writes its ignore list | yes, same script on the F1 pin | no | no | F1 lane sets a job-local Composer home ignore for those two IDs only |
| MariaDB member-profile migration | mariadb-migration | test/member-profile-mariadb-migration.php | Requires `migrations/2026_09_12_000000_create_flatrate_member_profiles.php`, which the canary moved | no, the file exists on the pin | yes | no | Run this gate on the F1 pin. F2 keeps the file under `migrations-unqualified/` and does not execute it |
| 26 F1 contract assertions | contract | test/*.test.mjs | Expect F1 `extend.php`, F1 composer constraints, frontend registrations, and historical migration paths | no, they pass against the pin's tree | yes | no | F1 lane checks out the pin. F2 lane runs `test/f2-identity-canary.test.mjs` only |

The 26 contract names from the run are: package pins the PKCE-capable Flarum/FoF floor and Nicknames; trusted bridge exposes provision, ticket, and session routes; nickname migration selects nickname display and grants self-edit; masquerade dropdown validation column widens beyond varchar 255; HMAC/bearer bridge POST routes bypass browser CSRF and only those routes are exempted; forum loads the compiled direct-login bundle; email-teaser-policy file failure; activity emitter sources keep feature gate and privacy exclusions; extend.php registers FlatRate email driver and no global beforeSending filter; placeholder outbound suppression does not use a global recipient filter; IDENTITY-002 schema derives member_number from users.id; R3D reservation guard remains registered; 001B migration is a private projection table; 001B beta-access route is HMAC-only and container-resolvable; 001B leaves the production composer constraint unchanged; DASHBOARD-001B compiled dashboard asset is registered last and exists; frontend extender loads navigation source before renderers; FORUM-SUB-001 Conditional Follow Tags registration; companion registers seven forum JS paths; forum LESS paths register exactly once; legacy desktop IndexPage renderer gate; frontend extender loads the drawer bundle; extension registers the reply marker listeners; post marker migration; RejectReservedTechNickname inspects attributes.nickname only; count()+1 remains removed from the provisioner.

PHP steps after the node contract step did not run because that step exited 1. They are included in the F1 lane.

## Historical migrations

These files stay in `migrations-unqualified/`. Flarum does not load that directory. Moving them is not an F1-to-F2 data migration.

| File | Original effect | F2 disposition |
| --- | --- | --- |
| 2026_08_27_000000_enable_public_nicknames.php | Nickname settings and permissions | Unqualified. Must not run in the canary |
| 2026_08_28_000000_create_sso_bridge_tables.php | `flatrate_sso_tickets`, `flatrate_sso_nonces` | Active canary migration |
| 2026_08_30_000000_create_post_markers.php | `flatrate_post_markers` | Unqualified |
| 2026_09_01_000000_widen_masquerade_field_validation.php | Alters `fof_masquerade_fields.validation` when present | Unqualified |
| 2026_09_10_000000_create_activity_emitter_tables.php | Activity outbox tables | Unqualified |
| 2026_09_10_120000_activity_outbox_terminal_at.php | Activity outbox column | Unqualified |
| 2026_09_12_000000_create_flatrate_member_profiles.php | Member profile table | Unqualified |
| 2026_09_16_000000_repair_activity_table_prefix.php | Prefix repair for activity tables | Unqualified |
| 2026_10_06_000000_create_beta_tester_projection.php | `flatrate_beta_tester_access` | Unqualified |

## Advisory exception

| ID | Package | Scope |
| --- | --- | --- |
| PKSA-w9tt-7782-78jx | league/flysystem 1.x | F1 1.8.19 disposable job only |
| PKSA-pwh8-d4fr-nywn | league/flysystem 1.x | F1 1.8.19 disposable job only |

The exception is a job-local `COMPOSER_HOME`. It is not written to the extension `composer.json`, the F2 canary, or production.
