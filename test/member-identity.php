<?php

/**
 * FORUM-IDENTITY-002 member-number domain + display switching.
 * Run: php test/member-identity.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/src/Identity/MemberIdentity.php';
require $root.'/src/Identity/ReservedTechNickname.php';
require $root.'/src/Identity/MemberDisplayException.php';
require $root.'/src/Identity/MemberDisplayService.php';
require $root.'/src/Identity/MemberProfileBackfillPlanner.php';
require $root.'/src/Identity/MemberNicknamePresentation.php';

use FlatRate\SupabaseOAuth\Identity\MemberDisplayException;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayService;
use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use FlatRate\SupabaseOAuth\Identity\MemberNicknamePresentation;
use FlatRate\SupabaseOAuth\Identity\MemberProfileBackfillPlanner;
use FlatRate\SupabaseOAuth\Identity\ReservedTechNickname;

function assert_true(bool $cond, string $msg): void
{
    if (! $cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

assert_true(MemberIdentity::nickname(1) === 'tech_#1', 'tech_#1');
assert_true(MemberIdentity::nickname(42) === 'tech_#42', 'tech_#42');
assert_true(MemberIdentity::nickname(307) === 'tech_#307', 'tech_#307');
assert_true(MemberIdentity::nickname(307) !== 'tech_307', 'hash namespace != legacy');
assert_true(MemberIdentity::parseMemberNumber('tech_#322') === 322, 'parse 322');
assert_true(MemberIdentity::parseMemberNumber('tech_322') === null, 'legacy parse rejected');

$service = new MemberDisplayService();

$user308 = (object) ['id' => 308, 'username' => 'tech_aaaaaaaa', 'nickname' => 'tech_307'];
$profile308 = (object) [
    'display_mode' => 'custom',
    'custom_nickname' => 'tech_307',
    'custom_nickname_origin' => 'grandfathered',
];

$service->applyMemberNumber($user308, $profile308);
assert_true($user308->nickname === 'tech_#308', 'member mode nickname');
assert_true($profile308->display_mode === 'member_number', 'member mode');
assert_true($profile308->custom_nickname === 'tech_307', 'retained grandfathered');

$service->restoreCustom($user308, $profile308);
assert_true($user308->nickname === 'tech_307', 'restore grandfathered');
assert_true($profile308->display_mode === 'custom', 'custom mode after restore');

$service->applyTrustedCustom($user308, $profile308, 'DieselDave');
assert_true($user308->nickname === 'DieselDave', 'new custom');
assert_true($profile308->custom_nickname === 'DieselDave', 'one retained custom');
assert_true($profile308->custom_nickname_origin === 'user', 'origin user');

$service->applyMemberNumber($user308, $profile308);
assert_true($user308->nickname === 'tech_#308', 'back to member');
assert_true($profile308->custom_nickname === 'DieselDave', 'custom survives member mode');
$service->restoreCustom($user308, $profile308);
assert_true($user308->nickname === 'DieselDave', 'restore DieselDave');

try {
    $service->applyTrustedCustom($user308, $profile308, 'tech_42');
    assert_true(false, 'arbitrary legacy claim should fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'reserved_tech_nickname', 'reject tech_42');
}

try {
    $service->applyTrustedCustom($user308, $profile308, 'tech_#307');
    assert_true(false, 'other member identity should fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'reserved_tech_nickname', 'reject tech_#307');
}

$user308->nickname = 'DieselDave';
$profile308->custom_nickname = 'tech_307';
$profile308->custom_nickname_origin = 'grandfathered';
$service->applyTrustedCustom($user308, $profile308, 'tech_307');
assert_true($user308->nickname === 'tech_307', 'trusted restore of retained grandfathered');

try {
    $service->syncGenericCustom($user308, $profile308, 'tech_#308');
    assert_true(false, 'generic own member nickname should fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'reserved_tech_nickname', 'generic cannot type tech_#N');
}

$empty = (object) ['display_mode' => 'member_number', 'custom_nickname' => null, 'custom_nickname_origin' => null];
try {
    $service->restoreCustom($user308, $empty);
    assert_true(false, 'empty custom restore should fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'custom_nickname_required', 'CUSTOM_NICKNAME_REQUIRED');
}

$user100 = (object) ['id' => 100, 'username' => 'tech_bbbbbbbb', 'nickname' => 'ShopTalk'];
$heal100 = $service->planSelfHeal($user100);
assert_true($heal100['display_mode'] === 'custom', 'self-heal custom');
assert_true($heal100['custom_nickname'] === 'ShopTalk', 'self-heal keeps nickname');
assert_true($heal100['complete_temporary'] === false, 'real custom is not temp');

$temp = (object) ['id' => 324, 'username' => 'tech_a84f19c2', 'nickname' => 'tech_a84f19c2'];
$healTemp = $service->planSelfHeal($temp);
assert_true($healTemp['complete_temporary'] === true, 'temp routing nickname completes');
assert_true($healTemp['display_mode'] === 'member_number', 'temp completes to member mode');

$planner = new MemberProfileBackfillPlanner();
$plan = $planner->plan([
    ['id' => 307, 'nickname' => 'tech_306'],
    ['id' => 308, 'nickname' => 'tech_307'],
    ['id' => 3, 'nickname' => 'tech_0'],
    ['id' => 17, 'nickname' => 'tech_697'],
    ['id' => 34, 'nickname' => 'tech_3'],
    ['id' => 70, 'nickname' => 'tech_20030'],
    ['id' => 96, 'nickname' => 'tech_5596'],
    ['id' => 216, 'nickname' => 'tech_321'],
    ['id' => 322, 'nickname' => 'tech_20031'],
    ['id' => 323, 'nickname' => 'tech_20032'],
    ['id' => 1, 'nickname' => 'tech_#1'],
]);

assert_true($plan['VISIBLE_NICKNAME_MUTATION_COUNT'] === 0, 'no visible mutations');
assert_true($plan['HASH_MEMBER_NAMESPACE_COLLISION_COUNT'] === 0, 'no hash collisions');
assert_true($plan['BACKFILL_USER_COUNT'] === 11, '11 planned rows');
assert_true($plan['MEMBER_MODE_COUNT'] === 1, 'only tech_#1 is member mode');
assert_true($plan['CUSTOM_MODE_COUNT'] === 10, 'others grandfathered');
assert_true($plan['GRANDFATHERED_COUNT'] === 10, 'grandfather count');
assert_true(strlen($plan['BACKFILL_PLAN_SHA256']) === 64, 'sha256');

$byId = [];
foreach ($plan['rows'] as $row) {
    $byId[$row['user_id']] = $row;
}
assert_true($byId[308]['custom_nickname'] === 'tech_307', '308 keeps tech_307');
assert_true($byId[308]['reserved_member_nickname'] === 'tech_#308', '308 member identity');
assert_true($byId[308]['visible_nickname'] === 'tech_307', '308 visible unchanged');
assert_true($byId[322]['custom_nickname'] === 'tech_20031', '322 keeps 20031');
assert_true($byId[322]['reserved_member_nickname'] === 'tech_#322', '322 member identity');
assert_true($byId[1]['display_mode'] === 'member_number', 'already canonical');

$collisionPlan = $planner->plan([
    ['id' => 10, 'nickname' => 'tech_#11'],
]);
assert_true($collisionPlan['HASH_MEMBER_NAMESPACE_COLLISION_COUNT'] === 1, 'cross-owner hash collision counted');

assert_true(MemberNicknamePresentation::htmlText('tech_#322') === 'tech_#322', 'hash is not html');
assert_true(MemberNicknamePresentation::isSafeDisplayText('tech_#322') === true, 'safe display');
assert_true(MemberNicknamePresentation::htmlText('tech_<script>') === 'tech_&lt;script&gt;', 'escape');
assert_true(MemberNicknamePresentation::isSafeDisplayText("tech_#322\n# heading") === false, 'no multiline heading injection');

assert_true(ReservedTechNickname::matches('tech_1') === true, 'user 100 cannot claim tech_1');
assert_true(ReservedTechNickname::matches('tech_99') === true, 'user 100 cannot claim tech_99');
assert_true(ReservedTechNickname::matches('tech_20031') === true, 'user 100 cannot claim tech_20031');

fwrite(STDOUT, "PASS member-identity behavior\n");
fwrite(STDOUT, 'BACKFILL_PLAN_SHA256='.$plan['BACKFILL_PLAN_SHA256']."\n");
