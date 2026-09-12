<?php

/**
 * FORUM-IDENTITY-002 R1 — display policy + Flarum nickname rule replica.
 * Run: php test/member-display-policy.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/src/Identity/MemberIdentity.php';
require $root.'/src/Identity/ReservedTechNickname.php';
require $root.'/src/Identity/MemberDisplayException.php';
require $root.'/src/Identity/MemberDisplayService.php';
require $root.'/src/Identity/MemberDisplayPolicy.php';
require $root.'/test/fixtures/flarum-nicknames-1.8.3-rules.php';

use FlatRate\SupabaseOAuth\Identity\MemberDisplayException;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayPolicy;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayService;

function assert_true(bool $cond, string $msg): void
{
    if (! $cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$policy = new MemberDisplayPolicy();
assert_true(MemberDisplayPolicy::REQUIRES_EDIT_NICKNAME_PERMISSION === true, 'permission required');

$allowed = (object) ['id' => 324, 'canEditNickname' => true];
$denied = (object) ['id' => 324, 'canEditNickname' => false];
$other = (object) ['id' => 1, 'canEditNickname' => true];
$target = (object) ['id' => 324];

$policy->assertActorMayChangeDisplay($allowed, $target);

try {
    $policy->assertActorMayChangeDisplay($denied, $target);
    assert_true(false, 'editNickname denied must fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'permission_denied', 'denied code');
    assert_true($error->statusCode === 403, 'denied status');
}

try {
    $policy->assertActorMayChangeDisplay($other, $target);
    assert_true(false, 'other actor must fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'permission_denied', 'cross-user denied');
}

$empty = (object) [
    'display_mode' => 'member_number',
    'custom_nickname' => null,
    'custom_nickname_origin' => null,
];
try {
    $policy->resolveCustomAction($empty, null);
    assert_true(false, 'new member restore must fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'custom_nickname_required', 'empty retained');
}

$create = $policy->resolveCustomAction($empty, 'DieselDave');
assert_true($create['kind'] === 'edit_user', 'new custom uses EditUser');
assert_true($create['nickname'] === 'DieselDave', 'new custom nickname');

$grandfathered = (object) [
    'display_mode' => 'member_number',
    'custom_nickname' => 'tech_307',
    'custom_nickname_origin' => 'grandfathered',
];
$restore = $policy->resolveCustomAction($grandfathered, null);
assert_true($restore['kind'] === 'trusted_restore', 'grandfathered restore path');
assert_true($restore['nickname'] === 'tech_307', 'exact retained value');

$explicit = $policy->resolveCustomAction($grandfathered, 'tech_307');
assert_true($explicit['kind'] === 'trusted_restore', 'explicit grandfathered restore');

try {
    $policy->resolveCustomAction($grandfathered, 'tech_42');
    assert_true(false, 'arbitrary reserved claim must fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'reserved_tech_nickname', 'tech_42 rejected');
}

$userOriginReserved = (object) [
    'custom_nickname' => 'tech_307',
    'custom_nickname_origin' => 'user',
];
try {
    $policy->resolveCustomAction($userOriginReserved, null);
    assert_true(false, 'non-grandfathered reserved restore must fail');
} catch (MemberDisplayException $error) {
    assert_true($error->errorCode === 'reserved_tech_nickname', 'origin must be grandfathered');
}

$retainedUser = (object) [
    'custom_nickname' => 'DieselDave',
    'custom_nickname_origin' => 'user',
];
$editRestore = $policy->resolveCustomAction($retainedUser, null);
assert_true($editRestore['kind'] === 'edit_user', 'ordinary restore uses EditUser');
assert_true($editRestore['nickname'] === 'DieselDave', 'ordinary restore nickname');

$users = [
    ['id' => 100, 'username' => 'tech_bbbbbbbb', 'nickname' => 'ShopTalk'],
    ['id' => 324, 'username' => 'tech_a84f19c2', 'nickname' => 'tech_#324'],
    ['id' => 308, 'username' => 'tech_aaaaaaaa', 'nickname' => 'tech_307'],
];

foreach (['[', ']', '(', ')', '<', '>'] as $char) {
    $candidate = 'Dave'.$char.'X';
    assert_true(flarum_nicknames_1_8_3_has_forbidden_syntax($candidate) === true, "forbidden {$char}");
    assert_true(flarum_nicknames_1_8_3_accepts($candidate, 324, $users) === false, "reject {$char}");
}

assert_true(flarum_nicknames_1_8_3_accepts('DieselDave', 324, $users) === true, 'ordinary custom allowed');
assert_true(flarum_nicknames_1_8_3_accepts('tech_bbbbbbbb', 324, $users) === false, 'username collision');
assert_true(flarum_nicknames_1_8_3_accepts('ShopTalk', 324, $users) === false, 'nickname collision');
assert_true(flarum_nicknames_1_8_3_accepts('ShopTalk', 324, $users, false) === true, 'uniqueness off allows nickname collision');
assert_true(flarum_nicknames_1_8_3_unique_conflict('tech_a84f19c2', 100, $users, true) === true, 'other username');
assert_true(flarum_nicknames_1_8_3_unique_conflict('tech_a84f19c2', 324, $users, true) === false, 'own username ignored');

$service = new MemberDisplayService();
$user = (object) ['id' => 324, 'nickname' => 'tech_#324'];
$profile = (object) [
    'display_mode' => 'member_number',
    'custom_nickname' => 'DieselDave',
    'custom_nickname_origin' => 'user',
];
$service->applyMemberNumber($user, $profile);
assert_true($user->nickname === 'tech_#324', 'member mode');
assert_true($profile->custom_nickname === 'DieselDave', 'custom retained after member switch');
$service->restoreCustom($user, $profile);
assert_true($user->nickname === 'DieselDave', 'custom restore');
assert_true($profile->display_mode === 'custom', 'custom mode');

$listenerShouldSync = function (array $data): bool {
    $attributes = $data['attributes'] ?? null;

    return is_array($attributes) && array_key_exists('nickname', $attributes);
};
assert_true($listenerShouldSync(['attributes' => ['nickname' => 'DieselDave']]) === true, 'Saving nickname syncs');
assert_true($listenerShouldSync(['attributes' => ['bio' => 'x']]) === false, 'unrelated save does not sync');

fwrite(STDOUT, "PASS member-display-policy behavior\n");
