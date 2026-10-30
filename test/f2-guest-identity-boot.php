<?php

declare(strict_types=1);

/** Real F2 API, renderer, SEO and cache-boundary checks. Synthetic local data only. */
$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
if (!str_starts_with($root, '/tmp/') || !is_file($root.'/site.php')) throw new RuntimeException('Disposable /tmp app required');
chdir($root);
require_once $root.'/vendor/autoload.php';
try {
$app = (require $root.'/site.php')->bootApp();
$c = $app->getContainer();
$db = $c->make(\Illuminate\Database\ConnectionInterface::class);
$dbName = (string) $db->getDatabaseName();
if (!str_starts_with($dbName, 'fr_f2_acceptance_') && !str_starts_with($dbName, $root.'/')) throw new RuntimeException('Synthetic DB required');
$settings = $c->make(\Flarum\Settings\SettingsRepositoryInterface::class);
$ctx = $c->make(\FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext::class);
$projection = $c->make(\FlatRate\SupabaseOAuth\Identity\GuestIdentityProjection::class);
$guest = new \Flarum\User\Guest();
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; echo "PASS {$name}\n"; }
$users = [];
foreach (['legacy' => ['LegacyIdentitySentinel', 'LegacyNicknameSentinel'], 'neutral' => ['tech_1a2b3c4d', 'NeutralNicknameSentinel']] as $key => [$username, $nickname]) {
    $u = \Flarum\User\User::where('username', $username)->first() ?? new \Flarum\User\User();
    $u->username = $username; $u->email = strtolower($username).'@example.test'; $u->password = 'local-synthetic-test-only';
    $u->nickname = $nickname; $u->avatar_url = 'private-avatar-sentinel.png'; $u->is_email_confirmed = true; $u->joined_at ??= new DateTimeImmutable(); $u->save();
    $db->table('users')->where('id', $u->id)->update(['nickname' => $nickname]);
    $users[$key] = $u->fresh();
}
$legacy = $users['legacy']; $neutral = $users['neutral'];
$original = $db->table('users')->whereIn('id', [$legacy->id, $neutral->id])->orderBy('id')->get()->toJson();
$alias = $projection->publicAlias($legacy);
$slug = $projection->publicSlug($legacy);
check(preg_match('/^tech_[0-9a-f]{8}$/D', $alias) === 1 && $alias !== $legacy->username, 'legacy gets neutral fallback');
check($projection->publicAlias($neutral) === $neutral->username, 'linked neutral alias unchanged');
$client = $c->make(\Flarum\Api\Client::class);
$getUser = static function ($actor, $user) use ($client): array {
    $r = $client->withActor($actor)->get('/users/'.$user->id);
    check($r->getStatusCode() === 200, 'user API succeeds');
    return json_decode((string) $r->getBody(), true, 512, JSON_THROW_ON_ERROR)['data']['attributes'];
};
foreach ([$guest, $legacy, $guest, $legacy, $guest] as $actor) {
    $attrs = $getUser($actor, $legacy);
    if ($actor->isGuest()) {
        check($attrs['displayName'] === $alias && $attrs['username'] === $alias && $attrs['slug'] === $slug, 'guest API projects all identity fields');
        check($attrs['avatarUrl'] === null, 'guest avatar absent');
        check(!array_key_exists('flatRateMemberNumber', $attrs) && !array_key_exists('flatRateMemberNickname', $attrs), 'guest member metadata absent');
    } else {
        check($attrs['username'] === 'LegacyIdentitySentinel' && $attrs['displayName'] === 'LegacyNicknameSentinel', 'auth username and nickname preserved');
    }
    check($ctx->depth() === 0, 'API request restores viewer context');
}
$attrs = $getUser($guest, $neutral);
check($attrs['username'] === 'tech_1a2b3c4d' && $attrs['slug'] === 'tech_1a2b3c4d', 'neutral API route unchanged');
$slugger = $c->make(\Flarum\Http\SlugManager::class)->forResource(\Flarum\User\User::class);
check($slugger->fromSlug($slug, $guest)->id === $legacy->id, 'guest fallback profile route resolves');
check($slugger->fromSlug($legacy->username, $guest)->id === $legacy->id, 'historical username URL remains valid');
$routeResponse = $client->withActor($guest)->withQueryParams(['bySlug' => true])->get('/users/'.$slug);
check($routeResponse->getStatusCode() === 200, 'guest JSON API resolves fallback slug');
$formatter = $c->make('flarum.formatter');
$discussion = new \Flarum\Discussion\Discussion();
$discussion->title = 'Guest projection synthetic fixture'; $discussion->slug = 'guest-projection-synthetic-fixture';
$discussion->created_at = new DateTimeImmutable(); $discussion->user_id = $legacy->id; $discussion->comment_count = 1; $discussion->participant_count = 1; $discussion->save();
$post = new \Flarum\Post\CommentPost();
$post->discussion_id = $discussion->id; $post->user_id = $legacy->id; $post->number = 1; $post->created_at = new DateTimeImmutable();
$post->content = 'Hello @"LegacyNicknameSentinel"#'.$legacy->id.' from a synthetic fixture.'; $post->save();
$discussion->first_post_id = $post->id; $discussion->last_post_id = $post->id; $discussion->last_posted_at = $post->created_at; $discussion->last_posted_user_id = $legacy->id; $discussion->save();
foreach ([$guest, $legacy, $guest] as $actor) {
    $ctx->push($actor);
    try { $html = $formatter->render($post->getRawOriginal('content'), $post); } finally { $ctx->pop(); }
    if ($actor->isGuest()) {
        check(str_contains($html, $alias) && !str_contains($html, 'LegacyNicknameSentinel') && !str_contains($html, 'LegacyIdentitySentinel'), 'guest mention text omits stored identity');
        check(str_contains($html, $slug), 'guest mention link uses working fallback route');
    } else check(str_contains($html, 'LegacyNicknameSentinel'), 'authenticated mention unchanged');
}
check($c->make(\Flarum\Extension\ExtensionManager::class)->isEnabled('fof-seo'), 'real SEO extension enabled');
$handler = $app->getRequestHandler();
foreach (['/d/'.$discussion->id, '/u/'.$slug, '/u/LegacyIdentitySentinel'] as $path) {
    $request = (new \Laminas\Diactoros\ServerRequest())->withUri(new \Laminas\Diactoros\Uri('http://127.0.0.1:18941'.$path))->withMethod('GET')->withHeader('Accept', 'text/html');
    $r = $handler->handle($request); $html = (string) $r->getBody();
    check($r->getStatusCode() === 200, 'guest HTML/profile route returns 200');
    check(!str_contains($html, 'LegacyIdentitySentinel') && !str_contains($html, 'LegacyNicknameSentinel') && !str_contains($html, 'private-avatar-sentinel'), 'HTML boot/SEO omit stored identity');
    check(str_contains($html, $alias), 'HTML/SEO use fallback alias');
    if (str_starts_with($path, '/d/')) check(str_contains($html, 'application/ld+json'), 'SEO structured data is actually rendered');
    check($ctx->depth() === 0, 'HTML request restores context');
    check(!preg_match('/(?:public|s-maxage)/i', $r->getHeaderLine('Cache-Control')), 'guest identity response not public-cacheable');
}
$seoRequest = (new \Laminas\Diactoros\ServerRequest())->withUri(new \Laminas\Diactoros\Uri('http://127.0.0.1:18941/u/LegacyIdentitySentinel'))->withAttribute('routeName', 'user')->withAttribute('locale', 'en')->withQueryParams(['username' => 'LegacyIdentitySentinel']);
$seoRequest = \Flarum\Http\RequestUtil::withActor($seoRequest, $legacy);
$seoDocument = $c->makeWith(\Flarum\Frontend\Document::class, ['forumApiDocument' => [], 'request' => $seoRequest]);
$ctx->push($legacy);
try { $c->make(\FoF\Seo\Listeners\PageListener::class)($seoDocument, $seoRequest); } finally { $ctx->pop(); }
$authSeo = json_encode([$seoDocument->head, $seoDocument->meta], JSON_THROW_ON_ERROR);
check(str_contains($authSeo, 'LegacyIdentitySentinel') && str_contains($authSeo, 'LegacyNicknameSentinel') && str_contains($authSeo, 'private-avatar-sentinel'), 'authenticated profile SEO metadata unchanged');
check($db->table('users')->whereIn('id', [$legacy->id, $neutral->id])->orderBy('id')->get()->toJson() === $original, 'projection never mutates stored users');
check($ctx->depth() === 0, 'final viewer context clear');
echo "F2_GUEST_IDENTITY_BOOT=PASS CHECKS={$checks}\n";

} catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); }
