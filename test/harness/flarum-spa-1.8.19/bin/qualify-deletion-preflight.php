<?php

/**
 * Disposable Flarum 1.8.19 qualification for ACCOUNT-DELETION-001C
 * read-only Community deletion preflight.
 *
 * Requires the SPA harness HTTP server and FORUM_SSO_SHARED_SECRET.
 */

declare(strict_types=1);

$flarumDir = $argv[1] ?? '';
$baseUrl = getenv('FLARUM_BASE_URL') ?: 'http://127.0.0.1:8080';
$secret = trim((string) getenv('FORUM_SSO_SHARED_SECRET'));

if ($flarumDir === '' || ! is_file($flarumDir.'/site.php') || strlen($secret) < 32) {
    fwrite(STDERR, "usage: FORUM_SSO_SHARED_SECRET=... php qualify-deletion-preflight.php <flarum-dir>\n");
    exit(1);
}

$site = require $flarumDir.'/site.php';
$app = $site->bootApp();
$db = $app->getContainer()->make('flarum.db');

$failures = 0;

function passDeletion(string $label): void
{
    echo "[PASS] {$label}\n";
}

function failDeletion(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n");
}

function assertDeletion(bool $cond, string $label, string $detail = ''): void
{
    $cond ? passDeletion($label) : failDeletion($label, $detail);
}

function assertDeletionSame($expected, $actual, string $label): void
{
    if ($expected === $actual) {
        passDeletion($label);
        return;
    }

    failDeletion($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
}

function signedDeletionPost(
    string $baseUrl,
    string $routePath,
    string $secret,
    array $body,
    ?string $signature = null
): array {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if (! is_string($json)) {
        throw new RuntimeException('json_encode_failed');
    }

    $timestamp = time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
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
    ];
}

function linkedUserId(object $db, string $sub): ?int
{
    $row = $db->table('login_providers')
        ->where('provider', 'flatrate')
        ->where('identifier', $sub)
        ->first();

    return $row ? (int) $row->user_id : null;
}

$route = '/flatrate-sso/deletion/preflight';

$unlinkedBeforeUsers = (int) $db->table('users')->count();
$unlinked = signedDeletionPost($baseUrl, $route, $secret, [
    'sub' => 'account-deletion-001c-unlinked',
]);
assertDeletionSame(200, $unlinked['status'], 'unlinked preflight HTTP 200');
assertDeletionSame(false, $unlinked['json']['linked'] ?? null, 'unlinked linked=false');
assertDeletionSame(false, $unlinked['json']['community_cleanup_required'] ?? null, 'unlinked cleanup not required');
assertDeletionSame(false, $unlinked['json']['destructive_execute_supported'] ?? null, 'destructive execute unsupported');
assertDeletionSame($unlinkedBeforeUsers, (int) $db->table('users')->count(), 'unlinked preflight creates no user');
assertDeletion(linkedUserId($db, 'account-deletion-001c-unlinked') === null, 'unlinked preflight creates no provider link');

$sub = 'account-deletion-001c-linked';
$provision = signedDeletionPost($baseUrl, '/flatrate-sso/provision', $secret, [
    'sub' => $sub,
    'email' => 'account-deletion-001c@example.test',
    'email_verified' => true,
    'beta_tester_active' => false,
]);
assertDeletionSame(200, $provision['status'], 'linked fixture provision succeeds');
$userId = linkedUserId($db, $sub);
assertDeletion($userId !== null, 'linked fixture resolves by flatrate provider + sub');

$beforeUsers = (int) $db->table('users')->count();
$beforeProviders = (int) $db->table('login_providers')->count();
$beforePosts = (int) $db->table('posts')->count();
$beforeDiscussions = (int) $db->table('discussions')->count();

$linked = signedDeletionPost($baseUrl, $route, $secret, ['sub' => $sub]);
assertDeletion($linked['status'] === 200, 'linked preflight HTTP 200', 'status='.$linked['status'].' body='.$linked['raw']);
assertDeletionSame(true, $linked['json']['linked'] ?? null, 'linked linked=true');
assertDeletionSame(false, $linked['json']['root_admin_block'] ?? null, 'ordinary linked user not root-admin blocked');
assertDeletionSame('linked_user_present', $linked['json']['identity_integrity'] ?? null, 'identity integrity bounded');
assertDeletionSame(true, $linked['json']['community_cleanup_required'] ?? null, 'community cleanup required');
assertDeletionSame(false, $linked['json']['destructive_execute_supported'] ?? null, 'linked destructive execute unsupported');
assertDeletionSame('retain_detach_author', $linked['json']['public_content_policy'] ?? null, 'public content policy bounded');
assertDeletionSame('delete_authored_private_content', $linked['json']['private_direct_messages_policy'] ?? null, 'direct message policy bounded');
assertDeletionSame('delete_authored_private_content', $linked['json']['private_live_chat_policy'] ?? null, 'live policy bounded');
assertDeletionSame(1, $linked['json']['counts']['login_provider_links'] ?? null, 'linked provider count bounded');
assertDeletionSame($beforeUsers, (int) $db->table('users')->count(), 'preflight deletes no users');
assertDeletionSame($beforeProviders, (int) $db->table('login_providers')->count(), 'preflight deletes no provider links');
assertDeletionSame($beforePosts, (int) $db->table('posts')->count(), 'preflight deletes no posts');
assertDeletionSame($beforeDiscussions, (int) $db->table('discussions')->count(), 'preflight deletes no discussions');

foreach (['email', 'username', 'nickname', 'user_id', 'sub', 'token', 'message', 'content'] as $hidden) {
    assertDeletion(! array_key_exists($hidden, $linked['json']), "response omits {$hidden}");
}

$bad = signedDeletionPost($baseUrl, $route, $secret, ['sub' => $sub], str_repeat('ab', 32));
assertDeletion($bad['status'] === 401, 'bad HMAC denied', 'status='.$bad['status'].' body='.$bad['raw']);
assertDeletionSame('invalid_sso_signature', $bad['json']['error'] ?? null, 'bad HMAC bounded error');
assertDeletionSame($beforeUsers, (int) $db->table('users')->count(), 'bad HMAC deletes no users');

if ($failures > 0) {
    fwrite(STDERR, "ACCOUNT_DELETION_FLARUM_PREFLIGHT_FAILURES={$failures}\n");
    exit(1);
}

echo "ACCOUNT_DELETION_FLARUM_PREFLIGHT=PASS\n";
exit(0);
