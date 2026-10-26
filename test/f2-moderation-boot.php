<?php

declare(strict_types=1);

/**
 * Disposable Flarum 2.0.2 proof that a moderator can hide another member's
 * discussion and a different ordinary member cannot.
 */

use Flarum\Foundation\Application;
use Flarum\Group\Group;
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

function expect(bool $condition, string $name): void
{
    global $failures;
    if (! $condition) {
        $failures[] = $name;
    }
}

function makeUser(string $username, bool $moderator): User
{
    $user = User::query()->where('username', $username)->first() ?: new User();
    $user->username = $username;
    $user->email = $username.'@example.test';
    $user->is_email_confirmed = 1;
    $user->joined_at = $user->joined_at ?: new DateTimeImmutable('now');
    $user->password = $user->password ?: bin2hex(random_bytes(16));
    $user->save();
    if ($moderator && ! $user->groups()->where('groups.id', Group::MODERATOR_ID)->exists()) {
        $user->groups()->attach(Group::MODERATOR_ID);
    }

    return $user->refresh();
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

function errorCode(ResponseInterface $response): string
{
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }
    $decoded = json_decode($stream->getContents(), true);

    return is_array($decoded) ? (string) ($decoded['errors'][0]['code'] ?? '') : '';
}

$failures = [];
$author = makeUser('discussionauthor', false);
$member = User::query()->where('username', 'ordinarymember')->first();
$moderator = makeUser('forummoderator', true);
if (! $member || $member->isAdmin() || ! $moderator->groups->contains(Group::MODERATOR_ID)) {
    fwrite(STDERR, "ACTOR_FIXTURE_MISSING\n");
    exit(1);
}

$authorToken = SessionAccessToken::generate((int) $author->id);
$memberToken = SessionAccessToken::generate((int) $member->id);
$moderatorToken = SessionAccessToken::generate((int) $moderator->id);

[$cookies, $csrf] = guestSession($handler);
$created = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => [
            'title' => 'R8 moderation fixture',
            'content' => 'Another member will try to hide this.',
        ],
    ],
], $cookies, $csrf, 'Token '.$authorToken->token);
$createdBody = json_decode((string) $created->getBody(), true);
$discussionId = (int) ($createdBody['data']['id'] ?? 0);
expect($created->getStatusCode() === 201 && $discussionId > 0, 'AUTHOR_DISCUSSION_CREATED');

[$cookies, $csrf] = guestSession($handler);
$memberHide = send($handler, 'PATCH', '/api/discussions/'.$discussionId, [
    'data' => [
        'type' => 'discussions',
        'id' => (string) $discussionId,
        'attributes' => ['isHidden' => true],
    ],
], $cookies, $csrf, 'Token '.$memberToken->token);
expect($memberHide->getStatusCode() >= 400, 'MEMBER_HIDE_DENIED');
expect($db->table('discussions')->where('id', $discussionId)->whereNull('hidden_at')->exists(), 'MEMBER_HIDE_NOT_APPLIED');

[$cookies, $csrf] = guestSession($handler);
$moderatorHide = send($handler, 'PATCH', '/api/discussions/'.$discussionId, [
    'data' => [
        'type' => 'discussions',
        'id' => (string) $discussionId,
        'attributes' => ['isHidden' => true],
    ],
], $cookies, $csrf, 'Token '.$moderatorToken->token);
expect($moderatorHide->getStatusCode() === 200, 'MODERATOR_HIDE_HTTP_200');
expect($db->table('discussions')->where('id', $discussionId)->whereNotNull('hidden_at')->exists(), 'MODERATOR_HIDE_APPLIED');

if ($failures !== []) {
    fwrite(STDERR, "F2_MODERATION_BOOT=FAIL\n".implode("\n", $failures)."\n");
    fwrite(STDERR, 'CREATE_STATUS='.$created->getStatusCode().' CREATE_CODE='.errorCode($created)."\n");
    fwrite(STDERR, 'MEMBER_STATUS='.$memberHide->getStatusCode().' MEMBER_CODE='.errorCode($memberHide)."\n");
    fwrite(STDERR, 'MODERATOR_STATUS='.$moderatorHide->getStatusCode().' MODERATOR_CODE='.errorCode($moderatorHide)."\n");
    exit(1);
}

fwrite(STDOUT, "F2_MODERATION_BOOT=PASS\n");
fwrite(STDOUT, "FLARUM_VERSION=2.0.2\n");
fwrite(STDOUT, "CHECKS=5\n");
fwrite(STDOUT, "MEMBER_HIDE_OTHER=DENIED\n");
fwrite(STDOUT, "MODERATOR_HIDE_OTHER=PASS\n");
