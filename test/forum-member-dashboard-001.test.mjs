#!/usr/bin/env node
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const text = (rel) => readFileSync(join(ROOT, rel), "utf8");

const FORBIDDEN = [
  "zip_private",
  "activity_subject_id",
  "service_role",
  "garage",
  "vin",
  "questionnaire",
  "brand_subscriptions",
];

test("DASHBOARD-001A/B: owner DTO is actor-matched and public Member # stays public", () => {
  const serializer = text("src/Api/SerializeMemberProfile.php");
  const dto = text("src/Identity/OwnerDashboardDto.php");

  assert.match(serializer, /flatRateMemberNumber/);
  assert.match(serializer, /flatRateOwnerDashboard/);
  assert.match(serializer, /\$actor->id !== \$memberNumber/);
  assert.match(serializer, /OwnerDashboardDto::make\(\$actor->isAdmin\(\)\)/);
  assert.doesNotMatch(serializer, /OWNER_PROFILE_BRIDGE|service_role|zip_private/);

  const publicReturn = serializer.indexOf("return $exposed;");
  const ownerAssign = serializer.indexOf("flatRateOwnerDashboard");
  assert.ok(publicReturn >= 0 && ownerAssign > publicReturn);

  assert.match(dto, /SCHEMA_VERSION = 1/);
  assert.match(dto, /https:\/\/flatrate\.wiki\/account/);
  assert.match(dto, /\/settings/);
  assert.match(dto, /SECTION_IDS = \[/);
  assert.match(dto, /'overview'/);
  assert.match(dto, /'identity'/);
  assert.match(dto, /'contributions'/);
  assert.match(dto, /'account_security'/);
  assert.match(dto, /'notifications'/);
  const sectionBlock = dto.slice(
    dto.indexOf("SECTION_IDS"),
    dto.indexOf("PUBLIC_ATTRIBUTE_KEYS"),
  );
  assert.doesNotMatch(sectionBlock, /'merit'/);
  assert.doesNotMatch(sectionBlock, /'following'/);
  assert.doesNotMatch(sectionBlock, /'for_you'/);
  assert.doesNotMatch(sectionBlock, /'points'/);
});

test("DASHBOARD-001B: shell uses server DTO, not client hiding, and omits future modules", () => {
  const js = text("js/dist/member-dashboard.js");
  assert.match(js, /flatrate-wiki-member-dashboard/);
  assert.match(js, /FlatRateOwnerDashboard/);
  assert.match(js, /attribute\('flatRateOwnerDashboard'\)/);
  assert.match(js, /schema_version/);
  assert.match(js, /forum\/components\/UserPage/);
  assert.match(js, /forum\/components\/PostsUserPage/);
  assert.match(js, /override\(PostsUserPage\.prototype, 'content'/);
  assert.match(js, /forum\/components\/UserCard/);
  assert.match(js, /infoItems/);
  assert.match(js, /flatrate-member-number/);
  assert.match(js, /https:\/\/flatrate\.wiki\/account/);
  assert.match(js, /\/settings/);
  assert.match(js, /module\.exports = \{\}/);
  assert.doesNotMatch(js, /UserControls/);
  assert.doesNotMatch(js, /app\.session\.user === this\.user/);
  assert.doesNotMatch(js, /canRenderOwnerChrome = true/);
  assert.doesNotMatch(js, /Merit|Brands & Live|For You|Following|Garage|VIN/);
  assert.doesNotMatch(js, /type:\s*['"]number['"]/);
  assert.doesNotMatch(js, /name:\s*['"]member[_-]number['"]/);
  assert.doesNotMatch(js, /attribute\('email'\)|attribute\('phone'\)|user\.email\(/);
  for (const key of FORBIDDEN) {
    assert.doesNotMatch(js, new RegExp(key));
  }
});

test("DASHBOARD-001B: My Profile is primary and compatibility routes remain", () => {
  const locale = text("resources/locale/en.yml");
  const js = text("js/dist/member-dashboard.js");
  assert.match(locale, /profile_button:\s*My Profile/);
  assert.match(locale, /flatrate-dashboard:/);
  assert.match(locale, /Manage account & security/);
  assert.match(js, /SETTINGS_PATH:\s*'\/settings'/);
  assert.match(js, /ACCOUNT_URL:\s*'https:\/\/flatrate\.wiki\/account'/);
  assert.doesNotMatch(js, /window\.location\.replace\('\/settings'\)/);
  assert.doesNotMatch(js, /route\('settings'\).*redirect/);
});

test("DASHBOARD-001B: compiled dashboard asset is registered last and exists", () => {
  assert.equal(existsSync(join(ROOT, "js/dist/member-dashboard.js")), true);
  const extendPhp = text("extend.php");
  const member = extendPhp.indexOf("js/dist/member-display.js");
  const dashboard = extendPhp.indexOf("js/dist/member-dashboard.js");
  assert.ok(member >= 0 && dashboard > member);
});
