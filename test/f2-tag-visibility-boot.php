<?php

declare(strict_types=1);

/**
 * Disposable Flarum 2.0.2 native-tag fixture.
 *
 * Public Board and Tip are readable by guests and ordinary members through
 * the tag index, direct /t/{slug} links, and the discussion API. A restricted
 * hidden Brand tag keeps a stable id, slug, and discussion association, and
 * stays undiscoverable to guests and unauthorized members. These ids are
 * fixture identities, not a copy of the production database.
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

$enabled = json_decode((string) $db->table('settings')->where('key', 'extensions_enabled')->value('value'), true);
if (! is_array($enabled) || ! in_array('flarum-tags', $enabled, true)) {
    fwrite(STDERR, "TAGS_EXTENSION_DISABLED\n");
    exit(1);
}

$failures = [];
$checks = 0;

function expect(bool $condition, string $name): void
{
    global $failures, $checks;
    $checks++;
    if (! $condition) {
        $failures[] = $name;
    }
}

function send($handler, string $method, string $path, ?array $payload, string $accept, string $authorization, string $csrf = '', array $cookies = []): ResponseInterface
{
    $request = (new ServerRequest())
        ->withMethod($method)
        ->withUri(new Uri('http://forum008-r8.local'.$path))
        ->withHeader('Host', 'forum008-r8.local')
        ->withHeader('Accept', $accept)
        ->withCookieParams($cookies);
    if ($payload !== null) {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write(json_encode($payload, JSON_THROW_ON_ERROR));
        $stream->rewind();
        $request = $request
            ->withHeader('Content-Type', 'application/vnd.api+json')
            ->withBody($stream);
    }
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

function sessionCookies($handler, string $token): array
{
    [$cookies] = guestSession($handler);
    $id = $cookies['flarum_session'] ?? '';
    if (! is_string($id) || preg_match('/^[A-Za-z0-9]+$/', $id) !== 1) {
        return [];
    }
    $path = rtrim((string) getenv('FLARUM_ROOT'), '/').'/storage/sessions/'.$id;
    $data = is_file($path) ? unserialize((string) file_get_contents($path), ['allowed_classes' => false]) : null;
    if (! is_array($data)) {
        return [];
    }
    $data['access_token'] = $token;
    file_put_contents($path, serialize($data));

    return $cookies;
}

function bodyText(ResponseInterface $response): string
{
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }

    return $stream->getContents();
}

function jsonBody(ResponseInterface $response): array
{
    $decoded = json_decode(bodyText($response), true);

    return is_array($decoded) ? $decoded : [];
}

$member = User::query()->where('username', 'ordinarymember')->first();
if (! $member || $member->isAdmin()) {
    fwrite(STDERR, "MEMBER_FIXTURE_MISSING\n");
    exit(1);
}

$brand = User::query()->where('username', 'brandmember')->first();
if (! $brand) {
    $brand = new User();
    $brand->username = 'brandmember';
    $brand->email = 'brandmember@forum008-r8.invalid';
    $brand->is_email_confirmed = 1;
    $brand->joined_at = new DateTimeImmutable('now');
    $brand->password = bin2hex(random_bytes(16));
    $brand->save();
}
if (! $db->table('group_user')->where('user_id', $brand->id)->where('group_id', 3)->exists()) {
    $db->table('group_user')->insert(['user_id' => $brand->id, 'group_id' => 3]);
}
if (! $db->table('groups')->where('id', 5)->exists()) {
    $db->table('groups')->insert([
        'id' => 5,
        'name_singular' => 'Brand member',
        'name_plural' => 'Brand members',
        'color' => '#888888',
        'icon' => 'fas fa-tag',
        'is_hidden' => 0,
    ]);
}
if (! $db->table('group_user')->where('user_id', $brand->id)->where('group_id', 5)->exists()) {
    $db->table('group_user')->insert(['user_id' => $brand->id, 'group_id' => 5]);
}
$db->table('group_permission')->where('group_id', 5)->whereIn('permission', ['tag13.viewForum', 'tag13.startDiscussion'])->delete();
$db->table('group_permission')->insert([
    ['group_id' => 5, 'permission' => 'tag13.viewForum'],
    ['group_id' => 5, 'permission' => 'tag13.startDiscussion'],
]);

$db->table('discussion_tag')->whereIn('discussion_id', [301, 302, 303])->delete();
$db->table('posts')->whereIn('discussion_id', [301, 302, 303])->delete();
$db->table('discussions')->whereIn('id', [301, 302, 303])->delete();
$db->table('tags')->whereIn('id', [11, 12, 13])->delete();

$now = gmdate('Y-m-d H:i:s', time() - 3600);
$db->table('tags')->insert([
    [
        'id' => 11, 'name' => 'Board', 'slug' => 'board', 'description' => 'Public board',
        'position' => 0, 'is_restricted' => 0, 'is_hidden' => 0, 'is_primary' => 1,
        'discussion_count' => 1, 'created_at' => $now,
    ],
    [
        'id' => 12, 'name' => 'Tip', 'slug' => 'tip', 'description' => 'Public tip',
        'position' => 1, 'is_restricted' => 0, 'is_hidden' => 0, 'is_primary' => 1,
        'discussion_count' => 1, 'created_at' => $now,
    ],
    [
        'id' => 13, 'name' => 'Aston Martin', 'slug' => 'aston-martin', 'description' => 'Hidden brand',
        'position' => 2, 'is_restricted' => 1, 'is_hidden' => 1, 'is_primary' => 1,
        'discussion_count' => 1, 'created_at' => $now,
    ],
]);

$fixtures = [
    301 => ['title' => 'R8-BOARD-DISCUSSION', 'slug' => 'r8-board-discussion', 'tag' => 11],
    302 => ['title' => 'R8-TIP-DISCUSSION', 'slug' => 'r8-tip-discussion', 'tag' => 12],
    303 => ['title' => 'R8-HIDDEN-BRAND-DISCUSSION', 'slug' => 'r8-hidden-brand-discussion', 'tag' => 13],
];
foreach ($fixtures as $id => $fixture) {
    $db->table('discussions')->insert([
        'id' => $id,
        'title' => $fixture['title'],
        'slug' => $fixture['slug'],
        'comment_count' => 1,
        'participant_count' => 1,
        'created_at' => $now,
        'user_id' => $member->id,
        'last_posted_at' => $now,
        'last_posted_user_id' => $member->id,
        'is_private' => 0,
    ]);
    $postId = $db->table('posts')->insertGetId([
        'discussion_id' => $id,
        'number' => 1,
        'created_at' => $now,
        'user_id' => $member->id,
        'type' => 'comment',
        'content' => '<t><p>'.$fixture['title'].'</p></t>',
        'is_private' => 0,
    ]);
    $db->table('discussions')->where('id', $id)->update([
        'first_post_id' => $postId,
        'last_post_id' => $postId,
        'last_post_number' => 1,
    ]);
    $db->table('discussion_tag')->insert([
        'discussion_id' => $id,
        'tag_id' => $fixture['tag'],
        'created_at' => $now,
    ]);
}

$memberRaw = SessionAccessToken::generate((int) $member->id)->token;
$brand->refresh();
$brandRaw = SessionAccessToken::generate((int) $brand->id)->token;
$memberToken = 'Token '.$memberRaw;
$brandToken = 'Token '.$brandRaw;
$memberCookies = sessionCookies($handler, $memberRaw);
$brandCookies = sessionCookies($handler, $brandRaw);
$hidden = 'R8-HIDDEN-BRAND-DISCUSSION';

function slugs(array $document): array
{
    $slugs = [];
    foreach ($document['data'] ?? [] as $row) {
        if (is_array($row) && isset($row['attributes']['slug'])) {
            $slugs[] = (string) $row['attributes']['slug'];
        }
    }

    return $slugs;
}

foreach ([
    'guest' => ['authorization' => '', 'cookies' => []],
    'member' => ['authorization' => $memberToken, 'cookies' => $memberCookies],
] as $actor => $actorState) {
    $authorization = $actorState['authorization'];
    $actorCookies = $actorState['cookies'];
    $tags = send($handler, 'GET', '/api/tags', null, 'application/vnd.api+json', $authorization);
    $tagDocument = jsonBody($tags);
    $tagSlugs = slugs($tagDocument);
    expect($tags->getStatusCode() === 200, strtoupper($actor).'_TAG_INDEX_200');
    expect(in_array('board', $tagSlugs, true) && in_array('tip', $tagSlugs, true), strtoupper($actor).'_PUBLIC_TAGS_LISTED');
    expect(! in_array('aston-martin', $tagSlugs, true) && ! str_contains(bodyText($tags), $hidden), strtoupper($actor).'_HIDDEN_TAG_NOT_LISTED');

    $direct = send($handler, 'GET', '/api/tags/13', null, 'application/vnd.api+json', $authorization);
    expect($direct->getStatusCode() === 404 && ! str_contains(bodyText($direct), $hidden), strtoupper($actor).'_HIDDEN_TAG_DIRECT_404');

    $board = send($handler, 'GET', '/api/discussions/301', null, 'application/vnd.api+json', $authorization);
    $tip = send($handler, 'GET', '/api/discussions/302', null, 'application/vnd.api+json', $authorization);
    expect($board->getStatusCode() === 200 && str_contains(bodyText($board), 'R8-BOARD-DISCUSSION'), strtoupper($actor).'_BOARD_DISCUSSION_DIRECT');
    expect($tip->getStatusCode() === 200 && str_contains(bodyText($tip), 'R8-TIP-DISCUSSION'), strtoupper($actor).'_TIP_DISCUSSION_DIRECT');

    $secret = send($handler, 'GET', '/api/discussions/303', null, 'application/vnd.api+json', $authorization);
    expect($secret->getStatusCode() === 404 && ! str_contains(bodyText($secret), $hidden), strtoupper($actor).'_HIDDEN_DISCUSSION_DIRECT_404');

    $index = send($handler, 'GET', '/api/discussions', null, 'application/vnd.api+json', $authorization);
    $indexText = bodyText($index);
    expect($index->getStatusCode() === 200 && str_contains($indexText, 'R8-BOARD-DISCUSSION') && str_contains($indexText, 'R8-TIP-DISCUSSION'), strtoupper($actor).'_PUBLIC_DISCUSSIONS_LISTED');
    expect(! str_contains($indexText, $hidden) && ! str_contains($indexText, 'aston-martin'), strtoupper($actor).'_HIDDEN_DISCUSSION_NOT_LISTED');

    $filtered = send($handler, 'GET', '/api/discussions?filter[tag]=aston-martin', null, 'application/vnd.api+json', $authorization);
    expect(! str_contains(bodyText($filtered), $hidden), strtoupper($actor).'_HIDDEN_TAG_FILTER_EMPTY');

    foreach (['/t/board' => 'R8-BOARD-DISCUSSION', '/t/tip' => 'R8-TIP-DISCUSSION'] as $path => $marker) {
        $page = send($handler, 'GET', $path, null, 'text/html', $authorization, '', $actorCookies);
        expect($page->getStatusCode() === 200 && str_contains(bodyText($page), $marker), strtoupper($actor).'_PAGE_'.strtoupper(trim($path, '/')));
    }
    $hiddenPage = send($handler, 'GET', '/t/aston-martin', null, 'text/html', $authorization, '', $actorCookies);
    $tagsPage = send($handler, 'GET', '/tags', null, 'text/html', $authorization, '', $actorCookies);
    expect($hiddenPage->getStatusCode() === 404 && ! str_contains(bodyText($hiddenPage), $hidden), strtoupper($actor).'_HIDDEN_DEEP_LINK_404');
    expect($tagsPage->getStatusCode() === 200 && str_contains(bodyText($tagsPage), 'board') && str_contains(bodyText($tagsPage), 'tip') && ! str_contains(bodyText($tagsPage), 'aston-martin'), strtoupper($actor).'_TAGS_NAVIGATION');
}

$brandTags = send($handler, 'GET', '/api/tags', null, 'application/vnd.api+json', $brandToken);
$brandSlugs = slugs(jsonBody($brandTags));
expect(in_array('aston-martin', $brandSlugs, true), 'BRAND_MEMBER_SEES_HIDDEN_TAG');
$brandDiscussion = send($handler, 'GET', '/api/discussions/303', null, 'application/vnd.api+json', $brandToken);
expect($brandDiscussion->getStatusCode() === 200 && str_contains(bodyText($brandDiscussion), $hidden), 'BRAND_MEMBER_SEES_HIDDEN_DISCUSSION');
$brandPage = send($handler, 'GET', '/t/aston-martin', null, 'text/html', $brandToken, '', $brandCookies);
expect($brandPage->getStatusCode() === 200 && str_contains(bodyText($brandPage), $hidden), 'BRAND_MEMBER_DEEP_LINK');

$db->table('posts')->whereIn('user_id', [(int) $member->id, (int) $brand->id])->update([
    'created_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    'edited_at' => null,
]);
$before = (int) $db->table('discussions')->count();
[$cookies, $csrf] = guestSession($handler);
expect($csrf !== '', 'CREATE_CSRF_PRESENT');
$guestCreate = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => ['title' => 'R8 guest board create', 'content' => 'no'],
        'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '11']]]],
    ],
], 'application/vnd.api+json', '', $csrf, $cookies);
expect($guestCreate->getStatusCode() === 401 && (int) $db->table('discussions')->count() === $before, 'GUEST_CANNOT_CREATE_ON_BOARD');

$memberPublic = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => ['title' => 'R8 member board create', 'content' => 'yes'],
        'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '11']]]],
    ],
], 'application/vnd.api+json', $memberToken, ...array_reverse(guestSession($handler)));
$memberPublicBody = jsonBody($memberPublic);
$memberPublicId = (int) ($memberPublicBody['data']['id'] ?? 0);
expect($memberPublic->getStatusCode() === 201 && $memberPublicId > 0, 'MEMBER_CAN_CREATE_ON_BOARD');
expect((int) $db->table('discussion_tag')->where('discussion_id', $memberPublicId)->where('tag_id', 11)->count() === 1, 'MEMBER_BOARD_ASSOCIATION');
$db->table('posts')->where('user_id', $member->id)->update([
    'created_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    'edited_at' => null,
]);

$memberHidden = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => ['title' => 'R8 member hidden create', 'content' => 'no'],
        'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '13']]]],
    ],
], 'application/vnd.api+json', $memberToken, ...array_reverse(guestSession($handler)));
expect(in_array($memberHidden->getStatusCode(), [403, 404], true), 'MEMBER_CANNOT_CREATE_ON_HIDDEN_BRAND');
expect((int) $db->table('discussions')->where('title', 'R8 member hidden create')->count() === 0, 'MEMBER_HIDDEN_CREATE_STORED_NOTHING');

$brandCreate = send($handler, 'POST', '/api/discussions', [
    'data' => [
        'type' => 'discussions',
        'attributes' => ['title' => 'R8 brand member create', 'content' => 'yes'],
        'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '13']]]],
    ],
], 'application/vnd.api+json', $brandToken, ...array_reverse(guestSession($handler)));
$brandCreateId = (int) (jsonBody($brandCreate)['data']['id'] ?? 0);
expect($brandCreate->getStatusCode() === 201 && $brandCreateId > 0, 'BRAND_MEMBER_CAN_CREATE_ON_HIDDEN_BRAND');
expect((int) $db->table('discussion_tag')->where('discussion_id', $brandCreateId)->where('tag_id', 13)->count() === 1, 'BRAND_MEMBER_ASSOCIATION');

$memberStillDenied = send($handler, 'GET', '/api/discussions/'.$brandCreateId, null, 'application/vnd.api+json', $memberToken);
expect($memberStillDenied->getStatusCode() === 404 && ! str_contains(bodyText($memberStillDenied), 'R8 brand member create'), 'MEMBER_CANNOT_READ_NEW_HIDDEN_DISCUSSION');

$identity = [
    11 => 'board',
    12 => 'tip',
    13 => 'aston-martin',
];
foreach ($identity as $id => $slug) {
    $row = $db->table('tags')->where('id', $id)->first();
    expect($row !== null && $row->slug === $slug, 'TAG_IDENTITY_'.$id);
}
expect((int) $db->table('discussion_tag')->where('discussion_id', 301)->where('tag_id', 11)->count() === 1, 'DISCUSSION_TAG_301');
expect((int) $db->table('discussion_tag')->where('discussion_id', 302)->where('tag_id', 12)->count() === 1, 'DISCUSSION_TAG_302');
expect((int) $db->table('discussion_tag')->where('discussion_id', 303)->where('tag_id', 13)->count() === 1, 'DISCUSSION_TAG_303');
expect((string) $db->table('discussions')->where('id', 303)->value('slug') === 'r8-hidden-brand-discussion', 'HIDDEN_DISCUSSION_SLUG');

if ($failures !== []) {
    $codes = [];
    foreach ([
        'brand_page' => $brandPage,
        'member_public' => $memberPublic,
        'member_hidden' => $memberHidden,
        'brand_create' => $brandCreate,
    ] as $label => $response) {
        $decoded = json_decode(bodyText($response), true);
        $code = is_array($decoded) ? (string) ($decoded['errors'][0]['code'] ?? '') : '';
        $codes[] = $label.'='.$response->getStatusCode().($code !== '' ? ':'.$code : '');
    }
    fwrite(STDERR, 'F2_TAG_VISIBILITY_BOOT=FAIL CHECKS='.count($failures).' '.implode(',', $failures)."\n");
    fwrite(STDERR, implode(' ', $codes)."\n");
    exit(1);
}

echo "F2_TAG_VISIBILITY_BOOT=PASS\n";
echo "FLARUM_VERSION=2.0.2\n";
echo "PUBLIC_BOARD_AND_TIP=PASS\n";
echo "HIDDEN_BRAND_UNDISCOVERABLE=PASS\n";
echo "TAG_IDENTITY_AND_DEEP_LINKS=PASS\n";
echo "MEMBER_PERMISSIONS=PASS\n";
echo 'CHECKS='.$checks."\n";
