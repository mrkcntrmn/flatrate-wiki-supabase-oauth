<?php

declare(strict_types=1);

/**
 * Disposable Flarum 2.0.2 boot proof for the combined identity-gate source.
 *
 * Run inside an installed Flarum 2.0.2 app whose vendor copy of
 * flatrate/wiki-supabase-oauth is this branch, with the extension enabled.
 * Passwords come from the environment and are never printed.
 */

use Flarum\Foundation\Application;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ResponseInterface;

$root = getenv('FLARUM_ROOT') ?: '';
if ($root === '' || ! is_file($root.'/site.php')) {
    fwrite(STDERR, "FLARUM_ROOT must be an installed Flarum directory\n");
    exit(2);
}

$adminUsername = getenv('ADMIN_USERNAME') ?: '';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
$memberPassword = getenv('MEMBER_PASSWORD') ?: '';
if ($adminUsername === '' || $adminPassword === '' || $memberPassword === '') {
    fwrite(STDERR, "ADMIN_USERNAME, ADMIN_PASSWORD, and MEMBER_PASSWORD are required\n");
    exit(2);
}

chdir($root);
$site = require $root.'/site.php';
$app = $site->bootApp();
$handler = $app->getRequestHandler();
$db = $app->getContainer()->make('db');
$settings = $app->getContainer()->make(SettingsRepositoryInterface::class);

if (Application::VERSION !== '2.0.2') {
    fwrite(STDERR, 'FLARUM_VERSION='.Application::VERSION."\n");
    exit(1);
}

if (! class_exists(\FlatRate\SupabaseOAuth\Auth\NativeAdminRecoveryGate::class)) {
    fwrite(STDERR, "NATIVE_ADMIN_RECOVERY_GATE_CLASS=MISSING\n");
    exit(1);
}

$enabled = (string) $db->table('settings')->where('key', 'extensions_enabled')->value('value');
if (! str_contains($enabled, 'flatrate-wiki-supabase-oauth')) {
    fwrite(STDERR, "EXTENSION_NOT_ENABLED\n");
    exit(1);
}

$settings->set('allow_sign_up', '1');

$admin = User::query()->where('username', $adminUsername)->first();
if (! $admin || ! $admin->isAdmin()) {
    fwrite(STDERR, "ADMIN_FIXTURE_MISSING\n");
    exit(1);
}

$member = User::query()->where('username', 'ordinarymember')->first();
if (! $member) {
    $member = new User();
    $member->username = 'ordinarymember';
    $member->email = 'ordinarymember@example.test';
    $member->is_email_confirmed = 1;
    $member->joined_at = new DateTimeImmutable('now');
}
$member->password = $memberPassword;
$member->save();
if ($member->isAdmin()) {
    fwrite(STDERR, "MEMBER_FIXTURE_IS_ADMIN\n");
    exit(1);
}

$failures = [];

function expect(bool $condition, string $name): void
{
    global $failures;
    if (! $condition) {
        $failures[] = $name;
    }
}

function send($handler, string $method, string $path, array $payload, array $cookies, string $csrf): ResponseInterface
{
    $stream = new Stream('php://temp', 'wb+');
    $stream->write(json_encode($payload, JSON_THROW_ON_ERROR));
    $stream->rewind();

    $request = (new ServerRequest())
        ->withMethod($method)
        ->withUri(new Uri('http://forum008-r8.local'.$path))
        ->withHeader('Host', 'forum008-r8.local')
        ->withHeader('Accept', $method === 'GET' ? 'text/html' : 'application/json')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($stream)
        ->withCookieParams($cookies);

    if ($csrf !== '') {
        $request = $request->withHeader('X-CSRF-Token', $csrf);
    }

    return $handler->handle($request);
}

function cookieJar(ResponseInterface $response, array $existing): array
{
    foreach ($response->getHeader('Set-Cookie') as $line) {
        if (preg_match('/^([^=]+)=([^;]*)/', $line, $match) === 1) {
            $existing[$match[1]] = urldecode($match[2]);
        }
    }

    return $existing;
}

function csrf(ResponseInterface $response): string
{
    return $response->getHeaderLine('X-CSRF-Token');
}

function body(ResponseInterface $response): string
{
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }
    $text = $stream->getContents();
    $text = preg_replace('/"token"\s*:\s*"[^"]*"/', '"token":"[redacted]"', $text) ?? $text;

    return $text;
}

function issuedToken(ResponseInterface $response): bool
{
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }
    $text = $stream->getContents();

    return preg_match('/"token"\s*:\s*"[^"]{20,}"/', $text) === 1;
}

function sessionUserId(array $cookies, $db): int
{
    $id = $cookies['flarum_session'] ?? '';
    if (! is_string($id) || preg_match('/^[A-Za-z0-9]+$/', $id) !== 1) {
        return -1;
    }

    $path = rtrim((string) getenv('FLARUM_ROOT'), '/').'/storage/sessions/'.$id;
    if (! is_file($path)) {
        return -1;
    }

    $data = unserialize((string) file_get_contents($path), ['allowed_classes' => false]);
    if (! is_array($data)) {
        return -1;
    }

    $accessToken = $data['access_token'] ?? null;
    if (! is_string($accessToken) || $accessToken === '') {
        return 0;
    }

    $row = $db->table('access_tokens')->where('token', $accessToken)->first();

    return $row ? (int) $row->user_id : 0;
}

function errorCode(ResponseInterface $response): string
{
    $decoded = json_decode(body($response), true);
    if (! is_array($decoded)) {
        return '';
    }

    return (string) ($decoded['errors'][0]['code'] ?? '');
}

function guestSession($handler): array
{
    $home = send($handler, 'GET', '/maintenance/login', [], [], '');
    $cookies = cookieJar($home, []);
    $token = csrf($home);

    return [$home, $cookies, $token];
}

[$home, $cookies, $token] = guestSession($handler);
expect(in_array($home->getStatusCode(), [200, 302], true) && $token !== '' && isset($cookies['flarum_session']), 'ANONYMOUS_SESSION_BOOTSTRAPPED');
expect(sessionUserId($cookies, $db) === 0, 'ANONYMOUS_SESSION_IS_GUEST');

$tokensBefore = (int) $db->table('access_tokens')->count();
$usersBefore = (int) $db->table('users')->count();

[$unused, $cookies, $token] = guestSession($handler);
$valid = send($handler, 'POST', '/login', [
    'identification' => $adminUsername,
    'password' => $adminPassword,
    'csrfToken' => $token,
], $cookies, $token);
$validBody = json_decode(body($valid), true);
$validCookies = cookieJar($valid, $cookies);
expect($valid->getStatusCode() === 200, 'ADMIN_VALID_PASSWORD_HTTP_200');
expect(is_array($validBody) && (int) ($validBody['userId'] ?? 0) === (int) $admin->id, 'ADMIN_VALID_PASSWORD_USER_ID');
expect(issuedToken($valid), 'ADMIN_VALID_PASSWORD_TOKEN_ISSUED');
expect(sessionUserId($validCookies, $db) === (int) $admin->id, 'ADMIN_VALID_PASSWORD_SESSION');
expect((int) $db->table('access_tokens')->count() === $tokensBefore + 1, 'ADMIN_VALID_PASSWORD_ONE_TOKEN');

$tokensAfterValid = (int) $db->table('access_tokens')->count();
[$unused, $cookies, $token] = guestSession($handler);
$invalid = send($handler, 'POST', '/login', [
    'identification' => $adminUsername,
    'password' => $adminPassword.'-wrong',
    'csrfToken' => $token,
], $cookies, $token);
$invalidCookies = cookieJar($invalid, $cookies);
expect($invalid->getStatusCode() === 401, 'ADMIN_INVALID_PASSWORD_HTTP_401');
expect(errorCode($invalid) === 'not_authenticated', 'ADMIN_INVALID_PASSWORD_CODE');
expect(sessionUserId($invalidCookies, $db) === 0, 'ADMIN_INVALID_PASSWORD_NO_SESSION');
expect((int) $db->table('access_tokens')->count() === $tokensAfterValid, 'ADMIN_INVALID_PASSWORD_NO_NEW_TOKEN');

[$unused, $cookies, $token] = guestSession($handler);
$memberLogin = send($handler, 'POST', '/login', [
    'identification' => 'ordinarymember',
    'password' => $memberPassword,
    'csrfToken' => $token,
], $cookies, $token);
$memberCookies = cookieJar($memberLogin, $cookies);
expect($memberLogin->getStatusCode() === 403, 'MEMBER_PASSWORD_HTTP_403');
expect(errorCode($memberLogin) === 'flatrate_sso_required', 'MEMBER_PASSWORD_CODE');
expect(sessionUserId($memberCookies, $db) === 0, 'MEMBER_PASSWORD_NO_SESSION');
expect((int) $db->table('access_tokens')->count() === $tokensAfterValid, 'MEMBER_PASSWORD_NO_TOKEN');

[$unused, $cookies, $token] = guestSession($handler);
$forged = send($handler, 'POST', '/api/users', [
    'data' => [
        'type' => 'users',
        'attributes' => [
            'username' => 'forgedactor',
            'email' => 'forgedactor@example.test',
            'password' => 'ForgedPassword-1',
            'token' => 'fabricated-registration-token',
        ],
    ],
], $cookies, $token);
expect($forged->getStatusCode() === 403, 'FABRICATED_TOKEN_HTTP_403');
expect(errorCode($forged) === 'invalid_confirmation_token', 'FABRICATED_TOKEN_REJECTED_BY_CORE');
expect((int) $db->table('users')->count() === $usersBefore, 'FABRICATED_TOKEN_NO_USER_ROW');
expect(User::query()->where('username', 'forgedactor')->doesntExist(), 'FABRICATED_TOKEN_USERNAME_ABSENT');

if ($failures !== []) {
    fwrite(STDERR, "F2_ADMIN_RECOVERY_BOOT=FAIL\n".implode("\n", $failures)."\n");
    fwrite(STDERR, 'VALID_STATUS='.$valid->getStatusCode().' VALID_BODY='.substr(body($valid), 0, 300)."\n");
    fwrite(STDERR, 'INVALID_STATUS='.$invalid->getStatusCode().' INVALID_BODY='.substr(body($invalid), 0, 300)."\n");
    fwrite(STDERR, 'MEMBER_STATUS='.$memberLogin->getStatusCode().' MEMBER_BODY='.substr(body($memberLogin), 0, 300)."\n");
    fwrite(STDERR, 'FORGED_STATUS='.$forged->getStatusCode().' FORGED_BODY='.substr(body($forged), 0, 400)."\n");
    exit(1);
}

fwrite(STDOUT, "F2_ADMIN_RECOVERY_BOOT=PASS\n");
fwrite(STDOUT, "FLARUM_VERSION=2.0.2\n");
fwrite(STDOUT, "CHECKS=19\n");
fwrite(STDOUT, "ADMIN_VALID_SESSION=PASS\n");
fwrite(STDOUT, "ADMIN_INVALID_PASSWORD=PASS\n");
fwrite(STDOUT, "MEMBER_PASSWORD_SSO_REQUIRED=PASS\n");
fwrite(STDOUT, "FABRICATED_REGISTRATION_TOKEN=PASS\n");
