<?php

/**
 * FORUM-IDENTITY-001-R3D TechNumber behavioral checks.
 * Run: php test/tech-number.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/src/Sso/SsoException.php';
require $root.'/src/Identity/TechNumber.php';
require $root.'/src/Identity/ReservedTechNickname.php';
require $root.'/src/Identity/NeutralIdentity.php';

use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
use FlatRate\SupabaseOAuth\Identity\ReservedTechNickname;
use FlatRate\SupabaseOAuth\Identity\TechNumber;
use FlatRate\SupabaseOAuth\Sso\SsoException;

function assert_true(bool $cond, string $msg): void
{
    if (! $cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

function expect_code(callable $fn, string $code, int $status): void
{
    try {
        $fn();
        fwrite(STDERR, "FAIL: expected {$code}\n");
        exit(1);
    } catch (SsoException $error) {
        assert_true($error->errorCode === $code, "code={$error->errorCode} expected {$code}");
        assert_true($error->statusCode === $status, "status={$error->statusCode} expected {$status}");
    }
}

assert_true(TechNumber::MIN_TECH_NUMBER === 20031, 'MIN_TECH_NUMBER');
assert_true(TechNumber::parseOptional([]) === null, 'absent => null');
assert_true(TechNumber::parseOptional(['tech_number' => 20031]) === 20031, '20031 valid');
assert_true(
    TechNumber::parseOptional(['tech_number' => TechNumber::MAX_SAFE_INTEGER]) === TechNumber::MAX_SAFE_INTEGER,
    'max safe valid'
);

expect_code(fn () => TechNumber::parseRequired([]), 'tech_number_required', 409);
expect_code(fn () => TechNumber::parseRequired(['tech_number' => null]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => null]), 'invalid_tech_number', 400);

expect_code(fn () => TechNumber::parseOptional(['tech_number' => 20030]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => 0]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => -1]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => 1.5]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => '20031']), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => 'abc']), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => false]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumber::parseOptional(['tech_number' => 'tech_20031']), 'invalid_tech_number', 400);
expect_code(
    fn () => TechNumber::parseOptional(['tech_number' => TechNumber::MAX_SAFE_INTEGER + 1]),
    'invalid_tech_number',
    400
);

assert_true(NeutralIdentity::nickname(20031) === 'tech_20031', 'nickname derivation');
assert_true(ReservedTechNickname::matches('tech_20031'), 'reservation accepts system nickname');

fwrite(STDOUT, "PASS tech-number behavior\n");
