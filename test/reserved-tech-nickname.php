<?php

/**
 * FORUM-IDENTITY-001-R3-A behavioral checks for reserved tech nickname guard.
 * Run: php test/reserved-tech-nickname.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/src/Identity/ReservedTechNickname.php';

use FlatRate\SupabaseOAuth\Identity\ReservedTechNickname;

function assert_true(bool $cond, string $msg): void
{
    if (! $cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$cases = [
    ['tech_999', true],
    ['TECH_999', true],
    ['Tech_999', true],
    ['tech_000999', true],
    ['tech_20031', true],
    ['DieselDan', false],
    ['tech_master', false],
    ['tech_diesel', false],
    ['technician_307', false],
    ['tech_', false],
    ['', false],
];

foreach ($cases as [$value, $expected]) {
    assert_true(ReservedTechNickname::matches($value) === $expected, "matches(".json_encode($value).") expected ".(int) $expected);
}

// Simulate listener decision table without full Flarum bootstrap.
function should_reject(array $data): bool
{
    $attributes = $data['attributes'] ?? null;
    if (! is_array($attributes) || ! array_key_exists('nickname', $attributes)) {
        return false;
    }

    return ReservedTechNickname::matches($attributes['nickname']);
}

assert_true(should_reject(['attributes' => []]) === false, 'unrelated save without nickname');
assert_true(should_reject(['attributes' => ['bio' => 'x']]) === false, 'unrelated attribute save');
assert_true(should_reject(['attributes' => ['nickname' => 'DieselDan']]) === false, 'custom nickname');
assert_true(should_reject(['attributes' => ['nickname' => 'tech_999']]) === true, 'human tech_999');
assert_true(should_reject(['attributes' => ['nickname' => 'TECH_999']]) === true, 'human TECH_999');
assert_true(should_reject(['attributes' => ['nickname' => 'tech_master']]) === false, 'non-numeric tech_master');

// Token path: nickname applied on model; request attributes omit nickname.
$tokenRegistrationData = [
    'attributes' => [
        'username' => 'tech_abcdef12',
        'email' => 'forum-example@users.flatrate.wiki',
        'token' => 'opaque',
    ],
];
assert_true(should_reject($tokenRegistrationData) === false, 'token registration exemption');

fwrite(STDOUT, "PASS reserved-tech-nickname behavior\n");
