<?php

declare(strict_types=1);

/**
 * Disposable Flarum 2.0.2 proof that a member can create a discussion and a
 * guest cannot. The member authenticates with a session access token because
 * ordinary password login is closed by the identity gate.
 */

use Flarum\Foundation\Application;
use Flarum\Http\SessionAccessToken;
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

chdir($root);
$site = require $root.'/site.php';
$app = $site->bootApp();
$handler = $app->getRequestHandler();
$db = $app->getContainer()->make('db');

if (Application::VERSION !== '2.0.2') {
    fwrite(STDERR, 'FLARUM_VERSION='.Application::VERSION."\n");
    exit(1);
}

$member = User::query()->where('username', 'ordinarymember')->first();
if (! $member || $member->isAdmin()) {
    fwrite(STDERR, "MEMBER_FIXTURE_MISSING\n");
    exit(1);
}

$memberToken = SessionAccessToken::generate((int) $member->id);
$failures = [];

function expect(bool $condition, string $name): void
{
    global $failures;
    if (! $condition) {
        $failures[] = $name;
    }
}

function send($handler, string $method, string $path, array $payload, array $cookies, string $csrf, string $authorization): ResponseInterface
{
    $stream = new Stream('php://temp', 'wb+');
    $stream->write(json_encode($payload, JSON_THROW_ON_ERROR));
    $stream->rewind();

    $request = (new ServerRequest())
        ->withMethod($method)
        ->withUri(new Uri('http://forum008-r8.local'.$path))
        ->withHeader('Host', 'forum008-r8.local')
        ->withHeader('Accept', 'application/json')
        ->withHeader('Content-Type', 'application/vnd.api+json')
        ->withBody($stream)
        ->withCookieParams($cookies);

    if ($csrf !== '') {
        $request = $request->withHeader('X-CSRF-Token', $csrf);
    }
    if ($authorization !== '') {
        $request = $request->withHeader('Authorization', $authorization);
    }

    return $handler->handle($request);
}

function guestSession($handler): array
{
    $request = (new ServerRequest())
        ->withMethod('GET')
        ->withUri(new Uri('http://forum008-r8.local/maintenance/login'))
        ->withHeader('Host', 'forum008-r8.local')
        ->withHeader('Accept', 'text/html');
    $response = $handler->handle($request);
    $cookies = [];
    foreach ($response->getHeader('Set-Cookie') as $line) {
        if (preg_match('/^([^=]+)=([^;]*)/', $line, $match) === 1) {
            $cookies[$match[1]] = urldecode($match[2]);
        }
    }

    return [$cookies, $response->getHeaderLine('X-CSRF-Token')];
}

function errorCode(ResponseInterface $response): string
{
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }
    $decoded = json_decode($stream->getContents(), true);

    return is_array($decoded) ? (string) ($decoded['errors'][0]['code'] ?? '') : '';
}

$before = (int) $db->table('discussions')->count();
[$cookies, $csrf] = guestSession($handler);
expect($csrf !== '', 'GUEST_CSRF_PRESENT');

$guest = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => [
            'title' => 'Guest must not create this',
            'content' => 'This post must not be stored.',
        ],
    ],
], $cookies, $csrf, '');
expect($guest->getStatusCode() === 401, 'GUEST_DISCUSSION_HTTP_401');
expect(errorCode($guest) === 'not_authenticated', 'GUEST_DISCUSSION_CODE');
expect((int) $db->table('discussions')->count() === $before, 'GUEST_DISCUSSION_NOT_STORED');

[$cookies, $csrf] = guestSession($handler);
$created = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => [
            'title' => 'R8 disposable member discussion',
            'content' => 'Created by the ordinary member on the disposable forum.',
        ],
    ],
], $cookies, $csrf, 'Token '.$memberToken->token);
$createdBody = json_decode((function (ResponseInterface $response): string {
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }

    return $stream->getContents();
})($created), true);
$createdId = (int) ($createdBody['data']['id'] ?? 0);
expect($created->getStatusCode() === 201, 'MEMBER_DISCUSSION_HTTP_201');
expect($createdId > 0, 'MEMBER_DISCUSSION_ID');
expect((int) $db->table('discussions')->count() === $before + 1, 'MEMBER_DISCUSSION_STORED');
$row = $db->table('discussions')->where('id', $createdId)->first();
expect($row !== null && (int) $row->user_id === (int) $member->id, 'MEMBER_DISCUSSION_AUTHOR');

if ($failures !== []) {
    fwrite(STDERR, "F2_DISCUSSION_CREATE_BOOT=FAIL\n".implode("\n", $failures)."\n");
    fwrite(STDERR, 'GUEST_STATUS='.$guest->getStatusCode().' GUEST_CODE='.errorCode($guest)."\n");
    fwrite(STDERR, 'MEMBER_STATUS='.$created->getStatusCode().' MEMBER_CODE='.errorCode($created)."\n");
    exit(1);
}

fwrite(STDOUT, "F2_DISCUSSION_CREATE_BOOT=PASS\n");
fwrite(STDOUT, "FLARUM_VERSION=2.0.2\n");
fwrite(STDOUT, "CHECKS=8\n");
fwrite(STDOUT, "GUEST_DISCUSSION_CREATE=DENIED\n");
fwrite(STDOUT, "MEMBER_DISCUSSION_CREATE=PASS\n");
