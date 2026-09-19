<?php

/**
 * In-process guest vs auth mention contentHtml gates for FORUM-PUBLIC-PSEUDONYM-001.
 * Run inside the disposable Flarum workdir:
 *   php /work/test/harness/flarum-spa-1.8.19/bin/qualify-guest-identity.php
 */

declare(strict_types=1);

$flarumDir = $argv[1] ?? getcwd();
$seedFile = $argv[2] ?? dirname(__DIR__).'/.work/seed.json';

if (! is_file($flarumDir.'/site.php') || ! is_file($seedFile)) {
    fwrite(STDERR, "usage: php qualify-guest-identity.php <flarum-dir> <seed.json>\n");
    exit(1);
}

$seed = json_decode(file_get_contents($seedFile));
$site = require $flarumDir.'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();

/** @var \FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext $ctx */
$ctx = $container->make(FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext::class);
$users = $container->make(Flarum\User\UserRepository::class);
$posts = $container->make(Flarum\Post\PostRepository::class);
$formatter = $container->make('flarum.formatter');

$failures = 0;
function expect_true(bool $cond, string $msg): void
{
    global $failures;
    if ($cond) {
        fwrite(STDOUT, "PASS {$msg}\n");
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$msg}\n");
}

$driver = $container->make('flarum.user.display_name.driver');
expect_true($driver instanceof FlatRate\SupabaseOAuth\Identity\GuestAwareDisplayNameDriver, 'VIEWER_CONTEXT_IMPLEMENTED driver decorated');
expect_true($ctx->isGuestProjectionActive() === false, 'NO_HTTP_CONTEXT_DELEGATION');

$sentinel = $users->findOrFail((int) $seed->sentinelUserId);
$member = $users->findOrFail((int) $seed->grandfatheredUserId);
$guest = new Flarum\User\Guest();
$post = $posts->findOrFail((int) $seed->mentionPostId);

// Ensure mention XML is formatter-valid.
$parseActor = $member;
$mentionText = 'Hello @"'.$seed->sentinelNickname.'"#'.$sentinel->id.' please advise.';
$xml = $formatter->parse($mentionText, $parseActor);
if (! is_string($xml) || ! str_starts_with(ltrim($xml), '<')) {
    fwrite(STDERR, "FAIL formatter parse did not return XML: ".$xml."\n");
    exit(1);
}
$container->make('flarum.db')->table('posts')->where('id', $post->id)->update(['content' => $xml]);
$post = $posts->findOrFail((int) $seed->mentionPostId);
$rawContent = $post->getAttributes()['content'] ?? '';
expect_true(is_string($rawContent) && str_starts_with(ltrim($rawContent), '<'), 'mention post stores formatter XML');

$ctx->push($guest);
try {
    $guestHtml = $formatter->render($rawContent, $post);
    $guestDisplay = $sentinel->display_name;
} finally {
    $ctx->pop();
}

$ctx->push($member);
try {
    $authHtml = $formatter->render($rawContent, $post);
    $authDisplay = $sentinel->display_name;
} finally {
    $ctx->pop();
}

$noCtxDisplay = $sentinel->display_name;

expect_true($guestDisplay === $seed->sentinelUsername, 'GUEST_DISPLAY_NAME_IS_NEUTRAL_ALIAS');
expect_true($authDisplay === $seed->sentinelNickname, 'AUTH_MENTION_CONTENT_HTML_REGRESSION display');
expect_true($noCtxDisplay === $seed->sentinelNickname, 'NO_HTTP_CONTEXT_DELEGATES_TO_INNER_DRIVER');
expect_true(str_contains($guestHtml, $seed->sentinelUsername), 'MENTION_CONTENT_HTML_GUEST alias');
expect_true(! str_contains($guestHtml, $seed->sentinelNickname), 'MENTION_CONTENT_HTML_GUEST no sentinel');
expect_true(str_contains($authHtml, $seed->sentinelNickname), 'MENTION_CONTENT_HTML_AUTH');

// Nested context restore
$ctx->push($member);
$ctx->push($guest);
expect_true($ctx->isGuestProjectionActive() === true, 'nested guest active');
$ctx->pop();
expect_true($ctx->isGuestProjectionActive() === false, 'VIEWER_CONTEXT_REENTRANT');
$ctx->pop();

try {
    $ctx->push($guest);
    throw new RuntimeException('boom');
} catch (RuntimeException $e) {
    $ctx->pop();
}
expect_true($ctx->depth() === 0, 'VIEWER_CONTEXT_EXCEPTION_CLEANUP');

$slugDriver = $container->make(Flarum\Http\SlugManager::class)->forResource(Flarum\User\User::class);
$slug = $slugDriver->toSlug($sentinel);
expect_true($slug === $seed->sentinelUsername, 'ACTIVE_USER_SLUG_DRIVER UsernameSlugDriver');
expect_true($slug === $sentinel->username, 'PROFILE_ROUTE_REGRESSION username slug');

fwrite(STDOUT, "GUEST_HTML={$guestHtml}\n");
fwrite(STDOUT, "AUTH_HTML={$authHtml}\n");

if ($failures > 0) {
    fwrite(STDERR, "qualify-guest-identity.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "qualify-guest-identity.php: all checks passed\n");
exit(0);
