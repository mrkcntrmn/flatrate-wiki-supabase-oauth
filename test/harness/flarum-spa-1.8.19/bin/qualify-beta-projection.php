<?php

/**
 * Disposable Flarum 1.8.19 qualification for the signed beta projection.
 * Requires the SPA harness HTTP server and FORUM_SSO_SHARED_SECRET.
 */

declare(strict_types=1);

$flarumDir = $argv[1] ?? '';
$baseUrl = getenv('FLARUM_BASE_URL') ?: 'http://127.0.0.1:8080';
$secret = trim((string) getenv('FORUM_SSO_SHARED_SECRET'));

if ($flarumDir === '' || ! is_file($flarumDir.'/site.php') || strlen($secret) < 32) {
    fwrite(STDERR, "usage: FORUM_SSO_SHARED_SECRET=... php qualify-beta-projection.php <flarum-dir>\n");
    exit(1);
}

$site = require $flarumDir.'/site.php';
$app = $site->bootApp();
$db = $app->getContainer()->make('flarum.db');

$failures = 0;

function pass(string $label): void
{
    echo "[PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n");
}

function assertTrue(bool $cond, string $label, string $detail = ''): void
{
    $cond ? pass($label) : fail($label, $detail);
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected === $actual) {
        pass($label);

        return;
    }
    fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
}

function signedPost(string $baseUrl, string $routePath, string $secret, array $body, ?int $timestamp = null, ?string $nonce = null, ?string $signature = null): array
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if (! is_string($json)) {
        throw new RuntimeException('json_encode_failed');
    }
    $timestamp ??= time();
    $nonce ??= rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $canonical = implode("\n", [
        (string) $timestamp,
        $nonce,
        'POST',
        $routePath,
        hash('sha256', $json),
    ]);
    $signature ??= hash_hmac('sha256', $canonical, $secret);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-FlatRate-Timestamp: '.$timestamp,
                'X-FlatRate-Nonce: '.$nonce,
                'X-FlatRate-Signature: v1='.$signature,
            ]),
            'content' => $json,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $raw = file_get_contents($baseUrl.'/api'.$routePath, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    $decoded = json_decode((string) $raw, true);

    return [
        'status' => $status,
        'json' => is_array($decoded) ? $decoded : [],
        'raw' => (string) $raw,
        'nonce' => $nonce,
        'body' => $json,
    ];
}

function projection(object $db, int $userId): ?object
{
    return $db->table('flatrate_beta_tester_access')->where('user_id', $userId)->first();
}

function userIdForSub(object $db, string $sub): ?int
{
    $row = $db->table('login_providers')
        ->where('provider', 'flatrate')
        ->where('identifier', $sub)
        ->first();

    return $row ? (int) $row->user_id : null;
}

$forum = @file_get_contents($baseUrl.'/api');
assertTrue(is_string($forum) && $forum !== '', 'API boots');
assertTrue(is_string($forum) && ! str_contains($forum, 'beta_tester_active') && ! str_contains($forum, 'flatrate_beta_tester_access'), 'forum payload hides beta projection');

$beforeUsers = (int) $db->table('users')->count();
$unlinked = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-unlinked-subject',
    'beta_tester_active' => true,
]);
assertSame(200, $unlinked['status'], 'unlinked beta sync HTTP 200');
assertSame(true, $unlinked['json']['ok'] ?? null, 'unlinked ok');
assertSame(false, $unlinked['json']['linked'] ?? null, 'unlinked linked=false');
assertSame(false, $unlinked['json']['changed'] ?? null, 'unlinked changed=false');
assertSame($beforeUsers, (int) $db->table('users')->count(), 'unlinked sync creates no user');
assertTrue(userIdForSub($db, 'beta-001b-unlinked-subject') === null, 'unlinked sync creates no login provider');

$provisionTrue = signedPost($baseUrl, '/flatrate-sso/provision', $secret, [
    'sub' => 'beta-001b-provision-true',
    'email' => 'beta-001b-true@example.test',
    'email_verified' => true,
    'beta_tester_active' => true,
]);
assertSame(200, $provisionTrue['status'], 'signed provision true');
$trueUser = userIdForSub($db, 'beta-001b-provision-true');
assertTrue($trueUser !== null, 'provision true links user');
$trueRow = $trueUser ? projection($db, $trueUser) : null;
assertTrue($trueRow !== null && (int) $trueRow->active === 1, 'provision true projection active');

$provisionFalse = signedPost($baseUrl, '/flatrate-sso/provision', $secret, [
    'sub' => 'beta-001b-provision-false',
    'email' => 'beta-001b-false@example.test',
    'email_verified' => true,
    'beta_tester_active' => false,
]);
assertSame(200, $provisionFalse['status'], 'signed provision false');
$falseUser = userIdForSub($db, 'beta-001b-provision-false');
$falseRow = $falseUser ? projection($db, $falseUser) : null;
assertTrue($falseRow !== null && (int) $falseRow->active === 0, 'provision false projection inactive');

$absentBefore = $trueRow ? (string) $trueRow->synced_at : '';
sleep(1);
$provisionAbsent = signedPost($baseUrl, '/flatrate-sso/provision', $secret, [
    'sub' => 'beta-001b-provision-true',
    'email' => 'beta-001b-true@example.test',
    'email_verified' => true,
]);
assertSame(200, $provisionAbsent['status'], 'provision field absent still provisions');
$absentAfter = $trueUser ? projection($db, $trueUser) : null;
assertTrue($absentAfter !== null && (int) $absentAfter->active === 1, 'absent field leaves projection active');
assertSame($absentBefore, $absentAfter ? (string) $absentAfter->synced_at : '', 'absent field does not mutate synced_at');

$invalid = signedPost($baseUrl, '/flatrate-sso/provision', $secret, [
    'sub' => 'beta-001b-provision-true',
    'email' => 'beta-001b-true@example.test',
    'email_verified' => true,
    'beta_tester_active' => 'true',
]);
assertSame(400, $invalid['status'], 'invalid beta type is 400');
assertSame('invalid_beta_tester_active', $invalid['json']['error'] ?? null, 'invalid beta error code');

$badHmacBefore = $trueUser ? projection($db, $trueUser) : null;
$bad = signedPost($baseUrl, '/flatrate-sso/provision', $secret, [
    'sub' => 'beta-001b-provision-true',
    'email' => 'beta-001b-true@example.test',
    'email_verified' => true,
    'beta_tester_active' => false,
], null, null, str_repeat('ab', 32));
assertSame(401, $bad['status'], 'bad HMAC denied');
$badAfter = $trueUser ? projection($db, $trueUser) : null;
assertSame($badHmacBefore ? (string) $badHmacBefore->synced_at : '', $badAfter ? (string) $badAfter->synced_at : '', 'bad HMAC does not mutate projection');
assertSame(1, $badAfter ? (int) $badAfter->active : 0, 'bad HMAC leaves active projection');

$ticketTrue = signedPost($baseUrl, '/flatrate-sso/ticket', $secret, [
    'sub' => 'beta-001b-provision-false',
    'email' => 'beta-001b-false@example.test',
    'email_verified' => true,
    'return_to' => '/',
    'beta_tester_active' => true,
]);
assertSame(200, $ticketTrue['status'], 'signed ticket true');
assertSame(45, $ticketTrue['json']['expires_in'] ?? null, 'ticket TTL unchanged');
assertTrue(str_starts_with((string) ($ticketTrue['json']['entry_path'] ?? ''), '/auth/flatrate/session?ticket='), 'ticket entry path');
$ticketTrueRow = $falseUser ? projection($db, $falseUser) : null;
assertTrue($ticketTrueRow !== null && (int) $ticketTrueRow->active === 1, 'ticket true converged before response');

$ticketFalse = signedPost($baseUrl, '/flatrate-sso/ticket', $secret, [
    'sub' => 'beta-001b-provision-false',
    'email' => 'beta-001b-false@example.test',
    'email_verified' => true,
    'return_to' => '/',
    'beta_tester_active' => false,
]);
assertSame(200, $ticketFalse['status'], 'signed ticket false');
$ticketFalseRow = $falseUser ? projection($db, $falseUser) : null;
assertTrue($ticketFalseRow !== null && (int) $ticketFalseRow->active === 0, 'ticket false projection inactive');

$ticketAbsentBefore = $ticketFalseRow ? (string) $ticketFalseRow->synced_at : '';
sleep(1);
$ticketAbsent = signedPost($baseUrl, '/flatrate-sso/ticket', $secret, [
    'sub' => 'beta-001b-provision-false',
    'email' => 'beta-001b-false@example.test',
    'email_verified' => true,
    'return_to' => '/',
]);
assertSame(200, $ticketAbsent['status'], 'existing community ticket without beta field');
$ticketAbsentAfter = $falseUser ? projection($db, $falseUser) : null;
assertSame($ticketAbsentBefore, $ticketAbsentAfter ? (string) $ticketAbsentAfter->synced_at : '', 'ticket absent field does not mutate projection');

$linkedTrue = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => false,
]);
assertSame(200, $linkedTrue['status'], 'linked beta sync false');
assertSame(true, $linkedTrue['json']['linked'] ?? null, 'linked sync linked=true');
assertSame(true, $linkedTrue['json']['changed'] ?? null, 'linked false changes active row');
$linkedFalseRow = $trueUser ? projection($db, $trueUser) : null;
assertTrue($linkedFalseRow !== null && (int) $linkedFalseRow->active === 0, 'beta sync false is inactive');

$linkedAgain = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => false,
]);
assertSame(false, $linkedAgain['json']['changed'] ?? null, 'idempotent false changed=false');
$idempotentTrue = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => true,
]);
assertSame(true, $idempotentTrue['json']['changed'] ?? null, 'linked true changes inactive row');
$idempotentTrueAgain = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => true,
]);
assertSame(false, $idempotentTrueAgain['json']['changed'] ?? null, 'idempotent true changed=false');
foreach (['email', 'username', 'user_id', 'sub'] as $hidden) {
    assertTrue(! array_key_exists($hidden, $idempotentTrueAgain['json']), "beta sync response omits {$hidden}");
}

$stale = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => false,
], time() - 300);
assertSame(401, $stale['status'], 'stale timestamp denied');
assertSame('stale_sso_request', $stale['json']['error'] ?? null, 'stale timestamp error');

$replayBody = [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => false,
];
$replayNonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$firstReplay = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, $replayBody, null, $replayNonce);
assertSame(200, $firstReplay['status'], 'first signed beta sync accepted');
$replayStamp = $trueUser ? (string) (projection($db, $trueUser)->synced_at ?? '') : '';
$secondReplay = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, $replayBody, null, $replayNonce);
assertSame(401, $secondReplay['status'], 'replayed nonce denied');
assertSame('replayed_sso_request', $secondReplay['json']['error'] ?? null, 'replay error');
$replayAfter = $trueUser ? (string) (projection($db, $trueUser)->synced_at ?? '') : '';
assertSame($replayStamp, $replayAfter, 'replay does not mutate projection');

$missingSub = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'beta_tester_active' => true,
]);
assertSame(400, $missingSub['status'], 'missing sub denied');

$invalidSync = signedPost($baseUrl, '/flatrate-sso/beta-access', $secret, [
    'sub' => 'beta-001b-provision-true',
    'beta_tester_active' => 1,
]);
assertSame(400, $invalidSync['status'], 'invalid beta sync boolean denied');

if ($failures > 0) {
    fwrite(STDERR, "BETA_PROJECTION_FLARUM_FAILURES={$failures}\n");
    exit(1);
}

echo "FLARUM_1_8_19_BETA_PROJECTION=PASS\n";
exit(0);
