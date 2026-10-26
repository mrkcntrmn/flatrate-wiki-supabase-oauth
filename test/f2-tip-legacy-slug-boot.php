<?php

declare(strict_types=1);

/**
 * Disposable proof that /t/job-breakdown redirects to the live Tip slug
 * without renaming the tag or changing another board.
 */

use Flarum\Foundation\Application;
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

if (Application::VERSION !== '2.0.2') {
    fwrite(STDERR, 'FLARUM_VERSION='.Application::VERSION."\n");
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

function send($handler, string $method, string $path): ResponseInterface
{
    return $handler->handle(
        (new ServerRequest())
            ->withMethod($method)
            ->withUri(new Uri('http://forum008-r8.local'.$path))
            ->withHeader('Host', 'forum008-r8.local')
            ->withHeader('Accept', 'text/html')
    );
}

$now = gmdate('Y-m-d H:i:s', time() - 3600);
$tip = $db->table('tags')->where('slug', 'tip')->first();
if ($tip === null) {
    $tipId = (int) $db->table('tags')->max('id') + 1;
    $db->table('tags')->insert([
        'id' => $tipId,
        'name' => 'Tip',
        'slug' => 'tip',
        'position' => 1,
        'is_restricted' => 0,
        'is_hidden' => 0,
        'is_primary' => 1,
        'discussion_count' => 0,
        'created_at' => $now,
    ]);
    $tip = $db->table('tags')->where('id', $tipId)->first();
}
$tipId = (int) $tip->id;
$db->table('tags')->where('id', $tipId)->update(['name' => 'Tip']);
$other = $db->table('tags')->where('id', '!=', $tipId)->orderBy('id')->first();
if ($other === null) {
    $db->table('tags')->insert([
        'id' => 11,
        'name' => 'BMW',
        'slug' => 'bmw',
        'position' => 3,
        'is_restricted' => 0,
        'is_hidden' => 0,
        'is_primary' => 1,
        'discussion_count' => 0,
        'created_at' => $now,
    ]);
    $other = $db->table('tags')->where('id', 11)->first();
}
$otherSlugBefore = (string) $other->slug;
$relationCountBefore = (int) $db->table('discussion_tag')->count();

$legacy = send($handler, 'GET', '/t/job-breakdown');
$location = $legacy->getHeaderLine('Location');
expect($legacy->getStatusCode() === 301, 'LEGACY_STATUS_301');
expect($location === '/t/tip', 'LEGACY_LOCATION_TIP');

$withQuery = send($handler, 'GET', '/t/job-breakdown?sort=latest');
expect($withQuery->getStatusCode() === 301, 'LEGACY_QUERY_STATUS');
expect($withQuery->getHeaderLine('Location') === '/t/tip?sort=latest', 'LEGACY_QUERY_PRESERVED');

$unsafe = send($handler, 'GET', '/t/job-breakdown?next=https://evil.example');
expect($unsafe->getStatusCode() === 301, 'UNSAFE_QUERY_STATUS');
expect($unsafe->getHeaderLine('Location') === '/t/tip', 'UNSAFE_QUERY_DROPPED');

$head = send($handler, 'HEAD', '/t/job-breakdown');
expect($head->getStatusCode() === 301 && $head->getHeaderLine('Location') === '/t/tip', 'HEAD_REDIRECT');

$post = send($handler, 'POST', '/t/job-breakdown');
expect($post->getStatusCode() !== 301, 'POST_NOT_REDIRECTED');

$current = send($handler, 'GET', '/t/tip');
$currentBody = $current->getBody();
if ($currentBody->isSeekable()) {
    $currentBody->rewind();
}
expect($current->getStatusCode() === 200, 'CURRENT_TIP_200');

expect((int) $db->table('tags')->where('slug', 'tip')->value('id') === $tipId, 'TIP_ID_PRESERVED');
expect((string) $db->table('tags')->where('id', $tipId)->value('slug') === 'tip', 'TIP_SLUG_UNCHANGED');
expect((string) $db->table('tags')->where('id', $tipId)->value('name') === 'Tip', 'TIP_NAME_UNCHANGED');
expect((string) $db->table('tags')->where('id', $other->id)->value('slug') === $otherSlugBefore, 'OTHER_SLUG_UNCHANGED');
expect((int) $db->table('discussion_tag')->count() === $relationCountBefore, 'DISCUSSION_TAG_RELATIONSHIPS_PRESERVED');

if ($failures !== []) {
    fwrite(STDERR, 'F2_TIP_LEGACY_SLUG_BOOT=FAIL '.implode(',', $failures)."\n");
    fwrite(STDERR, 'LEGACY_STATUS='.$legacy->getStatusCode().' LOCATION='.$location."\n");
    exit(1);
}

echo "F2_TIP_LEGACY_SLUG_BOOT=PASS\n";
echo "FLARUM_VERSION=2.0.2\n";
echo 'TIP_ID='.$tipId."\n";
echo "TIP_SLUG=tip\n";
echo "LEGACY_REDIRECT=301 /t/tip\n";
echo 'CHECKS='.$checks."\n";
