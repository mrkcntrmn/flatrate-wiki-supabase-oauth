<?php

declare(strict_types=1);

/**
 * Disposable Flarum 2.0.2 request proof for flatrate/flarum-media-privacy.
 *
 * The owner receives the private object with Cache-Control private, no-store.
 * A guest and a different member do not receive the bytes. A direct public
 * asset path does not serve the object, and an unsafe stored path is refused
 * before the file is read.
 */

use Flarum\Foundation\Application;
use Flarum\Foundation\Paths;
use Flarum\Http\SessionAccessToken;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
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
$paths = $app->getContainer()->make(Paths::class);

if (Application::VERSION !== '2.0.2') {
    fwrite(STDERR, 'FLARUM_VERSION='.Application::VERSION."\n");
    exit(1);
}
if (! class_exists(FlatRate\FlarumMediaPrivacy\Download\PrivateSharedAuthorizingDownloader::class)) {
    fwrite(STDERR, "MEDIA_PRIVACY_CLASS_MISSING\n");
    exit(1);
}
$enabled = json_decode((string) $db->table('settings')->where('key', 'extensions_enabled')->value('value'), true);
if (! is_array($enabled) || ! in_array('flatrate-media-privacy', $enabled, true) || ! in_array('fof-upload', $enabled, true)) {
    fwrite(STDERR, "MEDIA_PRIVACY_EXTENSION_DISABLED\n");
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

function send($handler, string $path, string $authorization): ResponseInterface
{
    $request = (new ServerRequest())
        ->withMethod('GET')
        ->withUri(new Uri('http://forum008-r8.local'.$path))
        ->withHeader('Host', 'forum008-r8.local')
        ->withHeader('Accept', 'application/octet-stream');
    if ($authorization !== '') {
        $request = $request->withHeader('Authorization', $authorization);
    }

    return $handler->handle($request);
}

function bodyText(ResponseInterface $response): string
{
    $stream = $response->getBody();
    if ($stream->isSeekable()) {
        $stream->rewind();
    }

    return $stream->getContents();
}

$owner = User::query()->where('username', 'ordinarymember')->first();
$wrong = User::query()->where('username', 'wrongmember')->first();
if (! $owner || $owner->isAdmin()) {
    fwrite(STDERR, "OWNER_FIXTURE_MISSING\n");
    exit(1);
}
if (! $wrong) {
    $wrong = new User();
    $wrong->username = 'wrongmember';
    $wrong->email = 'wrongmember@forum008-r8.invalid';
    $wrong->is_email_confirmed = 1;
    $wrong->joined_at = new DateTimeImmutable('now');
    $wrong->password = bin2hex(random_bytes(16));
    $wrong->save();
}

foreach ([2, 3] as $groupId) {
    $exists = $db->table('group_permission')->where('group_id', $groupId)->where('permission', 'fof-upload.download')->exists();
    if (! $exists) {
        $db->table('group_permission')->insert([
            'group_id' => $groupId,
            'permission' => 'fof-upload.download',
        ]);
    }
}

$secret = 'PRIVATE-MEDIA-BYTES-R8';
$relative = '2026-10-26/r8-private-object.bin';
$privateDir = $paths->storage.'/private-shared/files/2026-10-26';
if (! is_dir($privateDir) && ! mkdir($privateDir, 0700, true) && ! is_dir($privateDir)) {
    fwrite(STDERR, "PRIVATE_DIRECTORY_UNAVAILABLE\n");
    exit(1);
}
file_put_contents($privateDir.'/r8-private-object.bin', $secret);
$publicCopy = $paths->public.'/assets/files/'.$relative;
expect(! is_file($publicCopy), 'PRIVATE_OBJECT_NOT_IN_PUBLIC_ASSETS');

$uuid = 'r8-private-object';
$unsafeUuid = 'r8-unsafe-path';
$db->table('fof_upload_files')->whereIn('uuid', [$uuid, $unsafeUuid])->delete();
$now = gmdate('Y-m-d H:i:s');
$db->table('fof_upload_files')->insert([
    [
        'actor_id' => $owner->id,
        'base_name' => 'r8-private-object.bin',
        'path' => $relative,
        'url' => '/api/fof/download/'.$uuid,
        'type' => 'text/plain',
        'size' => strlen($secret),
        'upload_method' => 'private-shared',
        'created_at' => $now,
        'uuid' => $uuid,
        'hidden' => 1,
        'shared' => 1,
    ],
    [
        'actor_id' => $owner->id,
        'base_name' => 'config.php',
        'path' => '../config.php',
        'url' => '/api/fof/download/'.$unsafeUuid,
        'type' => 'text/plain',
        'size' => 1,
        'upload_method' => 'private-shared',
        'created_at' => $now,
        'uuid' => $unsafeUuid,
        'hidden' => 1,
        'shared' => 1,
    ],
]);

$ownerToken = 'Token '.SessionAccessToken::generate((int) $owner->id)->token;
$wrongToken = 'Token '.SessionAccessToken::generate((int) $wrong->id)->token;
$download = '/api/fof/download/'.$uuid;

$ownerResponse = send($handler, $download, $ownerToken);
$ownerBody = bodyText($ownerResponse);
$ownerCache = strtolower($ownerResponse->getHeaderLine('Cache-Control'));
expect($ownerResponse->getStatusCode() === 200 && str_contains($ownerBody, $secret), 'OWNER_DOWNLOAD_RETURNS_BYTES');
expect(str_contains($ownerCache, 'private') && str_contains($ownerCache, 'no-store'), 'OWNER_CACHE_CONTROL_PRIVATE_NO_STORE');
expect(! str_contains($ownerCache, 'public'), 'OWNER_CACHE_CONTROL_NOT_PUBLIC');

$guestResponse = send($handler, $download, '');
$guestBody = bodyText($guestResponse);
$guestCache = strtolower($guestResponse->getHeaderLine('Cache-Control'));
expect($guestResponse->getStatusCode() === 403 && ! str_contains($guestBody, $secret), 'GUEST_DOWNLOAD_DENIED');
expect(! str_contains($guestCache, 'public'), 'GUEST_DENIAL_NOT_PUBLICLY_CACHEABLE');

$wrongResponse = send($handler, $download, $wrongToken);
$wrongBody = bodyText($wrongResponse);
$wrongCache = strtolower($wrongResponse->getHeaderLine('Cache-Control'));
expect($wrongResponse->getStatusCode() === 403 && ! str_contains($wrongBody, $secret), 'WRONG_MEMBER_DOWNLOAD_DENIED');
expect(! str_contains($wrongCache, 'public'), 'WRONG_MEMBER_DENIAL_NOT_PUBLICLY_CACHEABLE');

foreach ([
    '/assets/files/'.$relative,
    '/assets/files/'.rawurlencode($relative),
    '/storage/private-shared/files/'.$relative,
    '/private-shared/files/'.$relative,
] as $index => $path) {
    $raw = send($handler, $path, '');
    expect($raw->getStatusCode() !== 200 || ! str_contains(bodyText($raw), $secret), 'RAW_BYPASS_'.($index + 1));
}

$unsafe = send($handler, '/api/fof/download/'.$unsafeUuid, $ownerToken);
$unsafeBody = bodyText($unsafe);
expect($unsafe->getStatusCode() === 403 && ! str_contains($unsafeBody, '<?php') && ! str_contains($unsafeBody, $secret), 'UNSAFE_PATH_DENIED_BEFORE_READ');
expect(is_file($paths->storage.'/private-shared/files/'.$relative), 'PRIVATE_OBJECT_REMAINS_IN_PRIVATE_STORAGE');

if ($failures !== []) {
    fwrite(STDERR, 'F2_MEDIA_PRIVACY_BOOT=FAIL CHECKS='.count($failures).' '.implode(',', $failures)."\n");
    exit(1);
}

echo "F2_MEDIA_PRIVACY_BOOT=PASS\n";
echo "FLARUM_VERSION=2.0.2\n";
echo "OWNER_DOWNLOAD=PASS\n";
echo "GUEST_DOWNLOAD=DENIED\n";
echo "WRONG_MEMBER_DOWNLOAD=DENIED\n";
echo "RAW_OBJECT_BYPASS=DENIED\n";
echo "CACHE_CONTROL=private, no-store\n";
echo 'CHECKS='.$checks."\n";
