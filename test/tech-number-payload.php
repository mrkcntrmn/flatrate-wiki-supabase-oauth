<?php

/**
 * FORUM-IDENTITY-001-R3D TechNumberPayload behavioral checks.
 * Run: php test/tech-number-payload.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/src/Sso/SsoException.php';
require $root.'/src/Identity/TechNumberPayload.php';

use FlatRate\SupabaseOAuth\Identity\TechNumberPayload;
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

assert_true(TechNumberPayload::parseOptional([]) === null, 'absent => null');
assert_true(TechNumberPayload::parseOptional(['tech_number' => null]) === null, 'null => null');
assert_true(TechNumberPayload::parseOptional(['tech_number' => 20031]) === 20031, '20031 valid');
assert_true(TechNumberPayload::parseOptional(['tech_number' => 1]) === 1, '1 valid');
assert_true(
    TechNumberPayload::parseOptional(['tech_number' => TechNumberPayload::MAX_SAFE_INTEGER]) === TechNumberPayload::MAX_SAFE_INTEGER,
    'max safe valid'
);

expect_code(fn () => TechNumberPayload::parseRequired([]), 'forum_tech_number_required', 409);
expect_code(fn () => TechNumberPayload::parseRequired(['tech_number' => null]), 'forum_tech_number_required', 409);

expect_code(fn () => TechNumberPayload::parseOptional(['tech_number' => 0]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumberPayload::parseOptional(['tech_number' => -1]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumberPayload::parseOptional(['tech_number' => 1.2]), 'invalid_tech_number', 400);
expect_code(fn () => TechNumberPayload::parseOptional(['tech_number' => '20031']), 'invalid_tech_number', 400);
expect_code(fn () => TechNumberPayload::parseOptional(['tech_number' => 'tech_20031']), 'invalid_tech_number', 400);
expect_code(fn () => TechNumberPayload::parseOptional(['tech_number' => true]), 'invalid_tech_number', 400);
expect_code(
    fn () => TechNumberPayload::parseOptional(['tech_number' => TechNumberPayload::MAX_SAFE_INTEGER + 1]),
    'invalid_tech_number',
    400
);

// System RegistrationToken nickname tech_20031 remains reserved-compatible.
require $root.'/src/Identity/ReservedTechNickname.php';
use FlatRate\SupabaseOAuth\Identity\ReservedTechNickname;
use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;

require $root.'/src/Identity/NeutralIdentity.php';
assert_true(NeutralIdentity::nickname(20031) === 'tech_20031', 'nickname derivation');
assert_true(ReservedTechNickname::matches('tech_20031'), 'reservation accepts system nickname');

fwrite(STDOUT, "PASS tech-number-payload behavior\n");
