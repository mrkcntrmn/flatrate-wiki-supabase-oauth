#!/usr/bin/env php
<?php

/**
 * Cross-language HMAC fixture gate for REP-001F.A1.
 * Expected signature is pinned in test/fixtures/forum-activity-hmac-v1.json.
 * Does not call Node to compute the answer.
 */

$root = dirname(__DIR__);
$fixturePath = $root.'/test/fixtures/forum-activity-hmac-v1.json';
$fixture = json_decode(file_get_contents($fixturePath), true);
if (! is_array($fixture)) {
    fwrite(STDERR, "fixture_unreadable\n");
    exit(1);
}

require_once $root.'/src/Activity/HmacSigner.php';

use FlatRate\SupabaseOAuth\Activity\HmacSigner;

$signer = new HmacSigner();
$actual = $signer->sign(
    $fixture['test_only_secret'],
    $fixture['timestamp'],
    $fixture['nonce'],
    $fixture['method'],
    $fixture['path'],
    $fixture['body']
);

$bodyHash = hash('sha256', $fixture['body']);
if ($bodyHash !== $fixture['body_sha256']) {
    fwrite(STDERR, "body_sha256_mismatch\n");
    exit(1);
}

if (! hash_equals(strtolower($fixture['signature_hex']), strtolower($actual))) {
    fwrite(STDERR, "signature_mismatch actual=$actual expected={$fixture['signature_hex']}\n");
    exit(1);
}

// Path binding: different path must not match fixture signature.
$other = $signer->sign(
    $fixture['test_only_secret'],
    $fixture['timestamp'],
    $fixture['nonce'],
    $fixture['method'],
    '/api/other',
    $fixture['body']
);
if (hash_equals(strtolower($fixture['signature_hex']), strtolower($other))) {
    fwrite(STDERR, "path_collision\n");
    exit(1);
}

echo "PHP_HMAC_VECTOR_PASS=true\n";
echo "CROSS_LANGUAGE_SIGNATURE_MATCH=true\n";
echo "HMAC_PROTOCOL_VERSION=1\n";
echo "FORUM_ACTIVITY_BRIDGE_SCHEMA_VERSION=1\n";
exit(0);
