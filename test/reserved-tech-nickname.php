<?php

/**
 * Reserved tech nickname guard — legacy tech_N and canonical tech_#N.
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

$legacy = [
    ['tech_999', true],
    ['TECH_999', true],
    ['Tech_999', true],
    ['tech_000999', true],
    ['tech_20031', true],
    ['tech_#307', false],
    ['DieselDan', false],
    ['tech_master', false],
    ['technician_307', false],
    ['tech_', false],
    ['', false],
];

foreach ($legacy as [$value, $expected]) {
    assert_true(ReservedTechNickname::matchesLegacy($value) === $expected, "legacy(".json_encode($value).")");
}

$canonical = [
    ['tech_#1', true],
    ['tech_#42', true],
    ['tech_#307', true],
    ['TECH_#307', true],
    ['Tech_#307', true],
    ['tech_307', false],
    ['DieselDave', false],
];

foreach ($canonical as [$value, $expected]) {
    assert_true(ReservedTechNickname::matchesCanonical($value) === $expected, "canonical(".json_encode($value).")");
}

assert_true(ReservedTechNickname::matches('tech_307') === true, 'matches legacy');
assert_true(ReservedTechNickname::matches('tech_#307') === true, 'matches canonical');
assert_true(ReservedTechNickname::matches('DieselDave') === false, 'custom allowed');
assert_true('tech_307' !== 'tech_#307', 'namespaces are distinct');

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
assert_true(should_reject(['attributes' => ['nickname' => 'tech_#322']]) === true, 'human tech_#322');
assert_true(should_reject(['attributes' => ['nickname' => 'TECH_#322']]) === true, 'human TECH_#322');
assert_true(should_reject(['attributes' => ['nickname' => 'tech_master']]) === false, 'non-numeric tech_master');

$tokenRegistrationData = [
    'attributes' => [
        'username' => 'tech_abcdef12',
        'email' => 'forum-example@users.flatrate.wiki',
        'token' => 'opaque',
    ],
];
assert_true(should_reject($tokenRegistrationData) === false, 'token registration exemption');

fwrite(STDOUT, "PASS reserved-tech-nickname behavior\n");
