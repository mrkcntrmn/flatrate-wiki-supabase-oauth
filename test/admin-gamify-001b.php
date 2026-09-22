<?php

/**
 * ADMIN-GAMIFY-001B — admin DTO/nav, API routes, bridge privacy, quality windows.
 */

$failures = 0;

function expect_true(bool $condition, string $message): void
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

$extend = (string) file_get_contents($root.'/extend.php');
$dto = (string) file_get_contents($root.'/src/Identity/OwnerDashboardDto.php');
$serializer = (string) file_get_contents($root.'/src/Api/SerializeMemberProfile.php');
$quality = (string) file_get_contents($root.'/src/AdminGamify/QualitySignalsService.php');
$bridgeClient = (string) file_get_contents($root.'/src/AdminGamify/AdminGamifyBridgeClient.php');
$bridgeConfig = (string) file_get_contents($root.'/src/AdminGamify/AdminGamifyBridgeConfig.php');
$overview = (string) file_get_contents($root.'/src/AdminGamify/OverviewController.php');
$frontend = (string) file_get_contents($root.'/js/dist/admin-gamification.js');
$locale = (string) file_get_contents($root.'/resources/locale/en.yml');

expect_true(str_contains($dto, "make(bool \$isAdmin = false)"), 'OwnerDashboardDto accepts isAdmin');
expect_true(str_contains($dto, "'gamification'"), 'admin section id present');
expect_true(str_contains($serializer, 'OwnerDashboardDto::make($actor->isAdmin())'), 'serializer admin-aware');
expect_true(str_contains($serializer, '$actor->id !== $memberNumber'), 'owner check precedes dashboard');

expect_true(str_contains($extend, 'admin-gamification.js'), 'admin JS registered');
expect_true(str_contains($extend, 'AdminGamifyServiceProvider'), 'admin service provider registered');
expect_true(str_contains($extend, '/flatrate-admin/gamification/overview'), 'overview route');
expect_true(str_contains($extend, '/flatrate-admin/gamification/quality'), 'quality route');
expect_true(str_contains($extend, '/flatrate-admin/gamification/sharing'), 'sharing route');
expect_true(str_contains($extend, '/flatrate-admin/gamification/referrals'), 'referrals route');

foreach (['OverviewController', 'QualityController', 'SharingController', 'ReferralsController'] as $class) {
    $src = (string) file_get_contents($root.'/src/AdminGamify/'.$class.'.php');
    expect_true(
        str_contains($src, 'extends AbstractAdminGamifyController'),
        $class.' extends admin base'
    );
}
$abstract = (string) file_get_contents($root.'/src/AdminGamify/AbstractAdminGamifyController.php');
expect_true(str_contains($abstract, 'assertAdmin'), 'ADMIN_API_ADMIN gate in abstract controller');

expect_true(str_contains($quality, 'post_votes'), 'QUALITY_CURRENT_BALLOT_AUTHORITY=post_votes');
expect_true(str_contains($quality, 'whereNull(\'posts.hidden_at\')'), 'QUALITY_HIDDEN_POST_EXCLUSION=PASS');
expect_true(str_contains($quality, 'current_effective_state/all_time'), 'QUALITY_FAKE_TIME_WINDOWS=false');
expect_true(str_contains($quality, 'canonical_ballot_state_has_no_trustworthy_timestamp'), 'timestamp honesty');
expect_true(! str_contains($quality, "'Technical Merit'"), 'do not label as Technical Merit');
expect_true(str_contains($quality, "'Quality Signals'"), 'Quality Signals label used');

expect_true(str_contains($bridgeConfig, 'ADMIN_GAMIFY_BRIDGE_SECRET'), 'dedicated bridge secret');
expect_true(
    str_contains($bridgeConfig, 'Never reuse FORUM_SSO_SHARED_SECRET'),
    'SSO secret widening explicitly forbidden'
);
expect_true(
    ! preg_match('/getenv\(\s*[\'"]FORUM_SSO_SHARED_SECRET[\'"]\s*\)/', $bridgeConfig),
    'SSO secret not resolved for admin bridge'
);
expect_true(str_contains($bridgeClient, 'X-FlatRate-Signature'), 'HMAC headers used');
expect_true(str_contains($bridgeClient, 'DEFAULT_TIMEOUT_SECONDS'), 'bridge timeout present');

expect_true(str_contains($overview, 'quality_signals'), 'overview includes quality');
expect_true(str_contains($frontend, 'flatrate-wiki-admin-gamification'), 'frontend initializer');
expect_true(str_contains($frontend, 'document.visibilityState'), 'poll only while visible');
expect_true(str_contains($frontend, 'Last updated') || str_contains($frontend, 'last_updated'), 'FRESHNESS_VISIBLE');
expect_true(str_contains($frontend, 'source_unavailable') || str_contains($frontend, 'Unavailable'), 'source failure UI');
expect_true(str_contains($frontend, '/u/:username/gamification'), 'admin route path');
expect_true(str_contains($locale, 'flatrate-admin-gamify'), 'locale present');

$forbiddenLeakNeedles = [
    'ADMIN_GAMIFY_BRIDGE_SECRET',
    'GROWTH_SHARE_E2E_UNLOCK_SECRET',
    'service_role',
    'SUPABASE_SERVICE_ROLE',
];
foreach ($forbiddenLeakNeedles as $needle) {
    expect_true(! str_contains($frontend, $needle), "BRIDGE_SECRET_SERIALIZED=false for {$needle}");
}

expect_true(! preg_match('/serializeToForum\([^\)]*bridge_secret/', $extend), 'bridge secret not forum-serialized');

if ($failures > 0) {
    fwrite(STDERR, "admin-gamify-001b.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "admin-gamify-001b.php: all checks passed\n");
exit(0);
