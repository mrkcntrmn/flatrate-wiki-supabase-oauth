<?php
declare(strict_types=1);
$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
if (!str_starts_with($root, '/tmp/')) throw new RuntimeException('Disposable lab required');
chdir($root); require $root.'/vendor/autoload.php'; $app = (require $root.'/site.php')->bootApp();
$c = $app->getContainer(); $db = $c->make(\Illuminate\Database\ConnectionInterface::class);
if (!str_starts_with($db->getDatabaseName(), 'fr_f2_acceptance_')) throw new RuntimeException('Unexpected database');
$fixture = json_decode(file_get_contents(getenv('F2_AUTH_FILE')), true, 512, JSON_THROW_ON_ERROR);
$settings = $c->make(\Flarum\Settings\SettingsRepositoryInterface::class);
$beforeGate = $settings->get('flatrate-voting.enabled');
$db->table('group_permission')->updateOrInsert(['group_id' => 3, 'permission' => 'discussion.votePosts'], []);
$member = \Flarum\User\User::findOrFail($fixture['users']['member']['id']);
$peer = \Flarum\User\User::findOrFail($fixture['users']['peer']['id']);
$outsider = \Flarum\User\User::findOrFail($fixture['users']['outsider']['id']);
$guest = new \Flarum\User\Guest();
$first = \Flarum\Post\Post::findOrFail($fixture['firstPost']);
$reply = \Flarum\Post\Post::where('discussion_id', $fixture['discussion'])->where('user_id', $peer->id)->where('type', 'comment')->firstOrFail();
$client = $c->make(\Flarum\Api\Client::class);
$votes = $c->make(\FlatRate\SupabaseOAuth\Voting\NativePlainVotes::class);
if (($argv[1] ?? '') === '--cast-worker') {
    $votes->cast($outsider, $first, 1);
    echo "NATIVE_CONCURRENT_CAST=PASS\n";
    exit(0);
}
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL '.$name); $checks++; echo 'PASS '.$name."\n"; };
$cast = static function ($actor, $post, $value) use ($client): int {
    $r = $client->withActor($actor)->withBody(['data' => ['type' => 'posts', 'id' => (string) $post->id, 'attributes' => ['flatRateVote' => $value]]])->patch('/posts/'.$post->id);
    return $r->getStatusCode();
};
$attrs = static function ($actor, string $path) use ($client, $check): array { $r = $client->withActor($actor)->get($path); $check($r->getStatusCode() === 200, 'read voting resource '.$path); return json_decode((string) $r->getBody(), true)['data']['attributes']; };
$oldSuspension = $outsider->suspended_until; $oldHidden = $reply->hidden_at;
try {
    $settings->set('flatrate-voting.enabled', '0');
    $check($cast($peer, $first, 1) === 403 && !$votes->canVote($peer, $first), 'closed gate rejects native API mutation');
    $settings->set('flatrate-voting.enabled', '1');
    $check(!$c->make(\Flarum\Extension\ExtensionManager::class)->isEnabled('fof-gamification'), 'retired Gamification stays disabled');
    $check($votes->canVote($peer, $first), 'open gate permits authorized non-author');
    $check($cast($peer, $first, 1) === 200, 'native F2 API persists upvote');
    $row = $db->table('post_votes')->where('post_id', $first->id)->where('user_id', $peer->id)->first();
    $check($row !== null && (int) $row->value === 1, 'canonical positive ballot stored');
    $check($cast($peer, $first, 1) === 200 && $db->table('post_votes')->where('post_id', $first->id)->where('user_id', $peer->id)->count() === 1, 'repeat cast is idempotent');
    $processes = [];
    for ($i = 0; $i < 3; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--cast-worker'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $check(proc_close($process) === 0 && str_contains($out, 'NATIVE_CONCURRENT_CAST=PASS'), 'independent concurrent ballot worker succeeds');
    }
    $check($db->table('post_votes')->where('post_id', $first->id)->where('user_id', $outsider->id)->where('value', 1)->count() === 1, 'simultaneous casts create exactly one canonical ballot');
    $check($cast($outsider, $first, 0) === 200, 'concurrency fixture ballot removed');
    $postAttrs = $attrs($peer, '/posts/'.$first->id);
    $check($postAttrs['flatRatePostUpvotes'] >= 1 && $postAttrs['flatRatePostViewerUpvoted'] === true, 'fresh post read preserves viewer vote and count');
    $discussion = $attrs($peer, '/discussions/'.$fixture['discussion']);
    $initialCount = $discussion['flatRateDiscussionUpvotes'];
    $check($initialCount >= 1 && $discussion['flatRateDiscussionViewerUpvoted'] === true && $discussion['flatRateDiscussionCanUpvote'] === false, 'discussion reflects persisted ballot and blocks a second discussion endorsement');
    $check($cast($member, $reply, 1) === 200, 'author of discussion can independently upvote another author reply');
    $discussion = $attrs($member, '/discussions/'.$fixture['discussion']);
    $check($discussion['flatRateDiscussionUpvotes'] === $initialCount + 1, 'whole-discussion aggregate includes reply votes');
    $check($cast($member, $first, 1) === 403, 'self-vote denied');
    $check($cast($guest, $first, 1) === 403, 'guest vote denied');
    $permission = ['group_id' => 3, 'permission' => 'discussion.votePosts'];
    $db->table('group_permission')->where($permission)->delete();
    $c->make(\Flarum\Group\PermissionCache::class)->flush();
    try {
        $unprivileged = \Flarum\User\User::findOrFail($peer->id);
        $check($cast($unprivileged, $first, 1) === 403, 'authenticated member without upvote permission denied');
    } finally {
        $db->table('group_permission')->updateOrInsert($permission, []);
        $c->make(\Flarum\Group\PermissionCache::class)->flush();
    }
    $check($cast($peer, $first, -1) === 422 && $cast($peer, $first, 2) === 422, 'downvotes and arbitrary vote values rejected');
    $outsider->suspended_until = new \DateTimeImmutable('+1 day'); $outsider->save();
    $check($cast($outsider, $first, 1) === 403, 'suspended member denied');
    $private = \Flarum\Post\Post::where('discussion_id', $fixture['privateDiscussion'])->where('type', 'comment')->firstOrFail();
    $check(in_array($cast($peer, $private, 1), [403, 404], true), 'private discussion ballot denied to outsider');
    $reply->hidden_at = new \DateTimeImmutable(); $reply->save();
    $check(!$votes->canVote($member, $reply), 'hidden reply cannot receive a ballot');
    $hiddenSummary = $attrs($peer, '/discussions/'.$fixture['discussion']);
    $check($hiddenSummary['flatRateDiscussionUpvotes'] === $initialCount, 'hidden reply excluded from visible aggregate');
    $reply->hidden_at = $oldHidden; $reply->save();
    foreach ([$guest, $member] as $actor) {
        $r = $client->withActor($actor)->withQueryParams(['include' => 'upvotes,downvotes'])->get('/posts/'.$first->id);
        $body = json_decode((string) $r->getBody(), true);
        $check($r->getStatusCode() === 400 || empty($body['data']['relationships']['upvotes']['data']), 'explicit include does not reveal voter identities');
    }
    $check($cast($peer, $first, 0) === 200, 'native API removes upvote');
    $neutral = $db->table('post_votes')->where('post_id', $first->id)->where('user_id', $peer->id)->first();
    $check($neutral->id === $row->id && (int) $neutral->value === 0, 'remove preserves ballot identity as neutral');
    $check($cast($member, $reply, 0) === 200, 'reply vote removal succeeds');
    $check($attrs($peer, '/posts/'.$first->id)['flatRatePostViewerUpvoted'] === false, 'removed ballot stays removed after reload');
    $migration = require dirname(__DIR__).'/migrations/2026_10_29_000000_native_plain_votes.php';
    $before = (array) $db->table('post_votes')->where('id', $row->id)->first();
    $migration['up']($db->getSchemaBuilder()); $migration['down']($db->getSchemaBuilder());
    $check((array) $db->table('post_votes')->where('id', $row->id)->first() === $before, 'migration rerun and rollback preserve historical ballots');
    $report = $c->make(\FlatRate\SupabaseOAuth\Voting\VotingReadiness::class)->build();
    $check(($report['provider']['kind'] ?? '') === 'flatrate-native-f2' && ($report['operating'] ?? false), 'admin readiness identifies actual native provider');
    echo "F2_NATIVE_VOTING=PASS CHECKS={$checks}\n";
} finally {
    $settings->set('flatrate-voting.enabled', $beforeGate);
    $outsider->suspended_until = $oldSuspension; $outsider->save();
    $reply->hidden_at = $oldHidden; $reply->save();
}
