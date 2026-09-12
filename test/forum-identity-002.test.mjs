import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const packageDir = new URL("../", import.meta.url);
const text = (path) => readFile(new URL(path, packageDir), "utf8");

test("IDENTITY-002: member identity helpers are distinct from TechNumber", async () => {
  const member = await text("src/Identity/MemberIdentity.php");
  const tech = await text("src/Identity/TechNumber.php");
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");

  assert.match(member, /tech_#/);
  assert.match(member, /users\.id|memberNumber/);
  assert.doesNotMatch(member, /20031/);
  assert.match(tech, /MIN_TECH_NUMBER = 20031/);
  assert.doesNotMatch(provisioner, /TechNumber::/);
  assert.doesNotMatch(provisioner, /count\(\)\s*\+\s*1/);
  assert.doesNotMatch(provisioner, /max\(\s*\$/);
});

test("IDENTITY-002: new registration assigns tech_#N after Flarum id exists", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /\$temporaryNickname = \$username/);
  assert.match(provisioner, /MemberIdentity::memberNumber\(\$user\)/);
  assert.match(provisioner, /MemberIdentity::nickname\(\$memberNumber\)/);
  assert.match(provisioner, /createForNewUser\(\$user\)/);
  assert.match(provisioner, /\$user->nickname = \$memberNickname/);
  assert.match(provisioner, /\$connection->transaction/);
});

test("IDENTITY-002: linked users self-heal without allocator", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /finishLinkedUser/);
  assert.match(provisioner, /selfHeal\(\$user\)/);
  assert.doesNotMatch(provisioner, /tech_number_required/);
});

test("IDENTITY-002: display API ignores client member_number", async () => {
  const controller = await text("src/Api/MemberDisplayController.php");
  assert.match(controller, /unset\(\$body\['member_number'\]\)/);
  assert.match(controller, /DISPLAY_MODE_MEMBER_NUMBER/);
  assert.match(controller, /applyTrustedCustom/);
  assert.match(controller, /assertRegistered/);
});

test("IDENTITY-002: serializer exposes self-only custom fields", async () => {
  const serializer = await text("src/Api/SerializeMemberProfile.php");
  assert.match(serializer, /flatRateMemberNumber/);
  assert.match(serializer, /flatRateMemberNickname/);
  assert.match(serializer, /flatRateNicknameMode/);
  assert.match(serializer, /flatRateCustomNickname/);
  assert.match(serializer, /\$actor->id !== \$memberNumber/);
});

test("IDENTITY-002: backfill command is dry-runnable and digest-guarded", async () => {
  const command = await text("src/Identity/BackfillMemberProfilesCommand.php");
  assert.match(command, /flatrate:member-numbers:backfill/);
  assert.match(command, /dry-run/);
  assert.match(command, /plan-sha256/);
  assert.match(command, /VISIBLE_NICKNAME_MUTATION_COUNT/);
  const store = await text("src/Identity/MemberProfileStore.php");
  assert.match(store, /BACKFILL_PLAN_DRIFT/);
});

test("IDENTITY-002: schema derives member_number from users.id", async () => {
  const migration = await text("migrations/2026_09_12_000000_create_flatrate_member_profiles.php");
  assert.match(migration, /flatrate_member_profiles/);
  assert.match(migration, /REFERENCES users \(id\)/);
  assert.match(migration, /member_number > 0/);
  assert.doesNotMatch(migration, /AUTO_INCREMENT/);
  assert.doesNotMatch(migration, /member_number_sequence/);
});

test("IDENTITY-002: settings UI lives on forum settings, not FlatRate account", async () => {
  const js = await text("js/dist/member-display.js");
  const locale = await text("resources/locale/en.yml");
  assert.match(js, /SettingsPage/);
  assert.match(js, /flatrate\/member-display/);
  assert.doesNotMatch(js, /flatrate\.wiki\/account/);
  assert.match(locale, /Community identity/);
  assert.match(locale, /Member #\{number\}/);
  assert.doesNotMatch(locale, /Flarum users\.id|allocator|Supabase sub|routing hash/);
});

test("IDENTITY-002: mentions stay on username; nickname is escaped display text", async () => {
  const presentation = await text("src/Identity/MemberNicknamePresentation.php");
  assert.match(presentation, /mentionIdentifier/);
  assert.match(presentation, /htmlText/);
  assert.match(presentation, /username/);
  assert.match(presentation, /htmlspecialchars/);
});

test("IDENTITY-002: concurrency uses Flarum id, not count/max/supabase", async () => {
  const provisioner = await text("src/Auth/FlatRateUserProvisioner.php");
  assert.match(provisioner, /MemberIdentity::memberNumber\(\$user\)/);
  assert.doesNotMatch(provisioner, /count\(\)\s*\+\s*1/);
  assert.doesNotMatch(provisioner, /max\(member_number\)|MAX\(member_number\)/);
  assert.doesNotMatch(provisioner, /ensure_forum_tech_assignment/);
});
