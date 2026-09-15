<?php

/**
 * FORUM-MEMBER-DASHBOARD-001A/B — owner DTO allowlist and omission gates.
 */

$failures = 0;

function expect(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDERR, "[PASS] {$message}\n");
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}\n");
}

$root = dirname(__DIR__);
require $root.'/src/Identity/OwnerDashboardDto.php';

use FlatRate\SupabaseOAuth\Identity\OwnerDashboardDto;

$dto = OwnerDashboardDto::make();

expect($dto['schema_version'] === 1, 'schema_version is 1');
expect($dto['account_url'] === 'https://flatrate.wiki/account', 'canonical account URL');
expect($dto['settings_path'] === '/settings', 'settings compatibility path');
expect(
    array_keys($dto) === ['schema_version', 'sections', 'account_url', 'settings_path'],
    'DTO top-level keys are allowlisted'
);

$ids = array_map(static fn (array $section): string => $section['id'], $dto['sections']);
expect($ids === OwnerDashboardDto::SECTION_IDS, 'section ids match frozen Phase 1 list');
expect(! in_array('merit', $ids, true), 'Merit omitted');
expect(! in_array('profile_privacy', $ids, true), 'private Profile & Privacy omitted');
expect(! in_array('brands_live', $ids, true), 'Brands & Live omitted');
expect(! in_array('garage', $ids, true), 'Garage omitted');
expect(! in_array('following', $ids, true), 'Following omitted');
expect(! in_array('for_you', $ids, true), 'For You omitted');
expect(! in_array('points', $ids, true), 'Points omitted');

$encoded = json_encode($dto);
expect(is_string($encoded) && $encoded !== '', 'DTO JSON encodes');
foreach (OwnerDashboardDto::FORBIDDEN_KEYS as $key) {
    expect(
        ! preg_match('/"'.preg_quote($key, '/').'"\s*:/', (string) $encoded),
        "forbidden key {$key} absent from DTO"
    );
}

$serializer = (string) file_get_contents($root.'/src/Api/SerializeMemberProfile.php');
expect(str_contains($serializer, 'flatRateOwnerDashboard'), 'serializer assigns owner dashboard');
expect(str_contains($serializer, 'OwnerDashboardDto::make()'), 'serializer uses versioned DTO');
expect(str_contains($serializer, '$actor->id !== $memberNumber'), 'owner DTO requires actor match');
expect(
    strpos($serializer, 'return $exposed;') < strpos($serializer, 'flatRateOwnerDashboard'),
    'public return precedes owner DTO'
);
expect(! str_contains($serializer, 'OWNER_PROFILE_BRIDGE'), 'no owner-profile bridge');
expect(! str_contains($serializer, 'service_role'), 'no service-role material');
expect(! str_contains($serializer, 'zip'), 'no ZIP field');
expect(! str_contains($serializer, 'activity_subject_id'), 'no activity_subject_id');

fwrite(STDERR, "MEMBER_NUMBER_PUBLIC=true\n");
fwrite(STDERR, "OWNER_PROFILE_BRIDGE=false\n");
fwrite(STDERR, "OWNER_DTO_ALLOWLISTED=true\n");

if ($failures > 0) {
    fwrite(STDERR, "owner-dashboard-dto.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "owner-dashboard-dto.php: all checks passed\n");
exit(0);
