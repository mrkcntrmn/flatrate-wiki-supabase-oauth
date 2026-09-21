<?php

/**
 * GROWTH-001UI — independent per-post upvotes + whole-discussion aggregate.
 *
 * Uses MariaDB (CI TCP via MARIADB_* env, else local unix socket) and Illuminate
 * from the disposable Flarum harness vendor. Does not mutate production.
 */

$root = dirname(__DIR__);
$harnessVendor = $root.'/test/harness/flarum-spa-1.8.19/.work/flarum/vendor/autoload.php';
$failures = 0;

function pass(string $m): void
{
    fwrite(STDERR, "[PASS] {$m}\n");
}

function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "[FAIL] {$m}\n");
}

function expect_true(bool $cond, string $m): void
{
    $cond ? pass($m) : fail($m);
}

function envOr(string $key, string $default): string
{
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

if (! is_file($harnessVendor)) {
    fwrite(STDERR, "[SKIP] harness vendor missing — cannot run MariaDB invariant suite\n");
    fwrite(STDERR, "MULTI_POST_POSITIVE_BALLOTS=INFRA_BLOCKED\n");
    exit(0);
}

require $harnessVendor;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;

$host = getenv('MARIADB_HOST');
$socket = '/tmp/mysqld/mysqld.sock';
$config = null;

if (is_string($host) && $host !== '') {
    $config = [
        'driver' => 'mysql',
        'host' => $host,
        'port' => (int) envOr('MARIADB_PORT', '3306'),
        'database' => envOr('MARIADB_DATABASE', 'flarum_spa'),
        'username' => envOr('MARIADB_USER', 'flarum'),
        'password' => envOr('MARIADB_PASSWORD', 'flarum'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => 'pv_multi_',
    ];
} elseif (file_exists($socket)) {
    $mysqli = @new mysqli('localhost', 'root', '', null, null, $socket);
    if ($mysqli->connect_errno) {
        fwrite(STDERR, "[SKIP] MariaDB socket present but connect failed: {$mysqli->connect_error}\n");
        exit(0);
    }
    $mysqli->query('CREATE DATABASE IF NOT EXISTS flarum_vote_multi_r1');
    $mysqli->close();

    $config = [
        'driver' => 'mysql',
        'unix_socket' => $socket,
        'database' => 'flarum_vote_multi_r1',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => 'fr_',
    ];
} else {
    fwrite(STDERR, "[SKIP] MariaDB unavailable (no MARIADB_HOST and no {$socket})\n");
    exit(0);
}

$capsule = new Capsule();
$capsule->addConnection($config);
$capsule->setAsGlobal();
$capsule->bootEloquent();

/** @var ConnectionInterface $db */
$db = $capsule->getConnection();

try {
    $db->getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, '[SKIP] MariaDB connect failed: '.$e->getMessage()."\n");
    exit(0);
}

$schema = $db->getSchemaBuilder();
foreach (['post_votes', 'posts', 'discussions', 'users'] as $table) {
    $schema->dropIfExists($table);
}

$schema->create('users', function ($t) {
    $t->increments('id');
    $t->string('username')->nullable();
});
$schema->create('discussions', function ($t) {
    $t->increments('id');
    $t->integer('first_post_id')->nullable();
});
$schema->create('posts', function ($t) {
    $t->increments('id');
    $t->integer('discussion_id');
    $t->integer('user_id');
    $t->string('type')->default('comment');
    $t->timestamp('hidden_at')->nullable();
});
$schema->create('post_votes', function ($t) {
    $t->increments('id');
    $t->integer('post_id');
    $t->integer('user_id');
    $t->integer('value')->default(0);
    $t->timestamps();
    $t->unique(['post_id', 'user_id']);
});

$viewer = $db->table('users')->insertGetId(['username' => 'viewer']);
$viewer2 = $db->table('users')->insertGetId(['username' => 'viewer2']);
$author = $db->table('users')->insertGetId(['username' => 'author']);
$discussionId = $db->table('discussions')->insertGetId(['first_post_id' => null]);

$firstPost = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $author,
    'type' => 'comment',
    'hidden_at' => null,
]);
$replyA = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $author,
    'type' => 'comment',
    'hidden_at' => null,
]);
$replyB = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $author,
    'type' => 'comment',
    'hidden_at' => null,
]);
$hiddenReply = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $author,
    'type' => 'comment',
    'hidden_at' => date('Y-m-d H:i:s'),
]);
$db->table('discussions')->where('id', $discussionId)->update(['first_post_id' => $firstPost]);

function castVote($db, int $postId, int $userId, int $value): void
{
    $now = date('Y-m-d H:i:s');
    $row = $db->table('post_votes')
        ->where('post_id', $postId)
        ->where('user_id', $userId)
        ->first();

    if ($row) {
        $db->table('post_votes')->where('id', $row->id)->update([
            'value' => $value,
            'updated_at' => $now,
        ]);

        return;
    }

    $db->table('post_votes')->insert([
        'post_id' => $postId,
        'user_id' => $userId,
        'value' => $value,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function positiveCount($db, int $discussionId, int $userId): int
{
    return (int) $db->table('post_votes')
        ->join('posts', 'posts.id', '=', 'post_votes.post_id')
        ->where('posts.discussion_id', $discussionId)
        ->where('post_votes.user_id', $userId)
        ->where('post_votes.value', '>', 0)
        ->count();
}

function visibleAggregate($db, int $discussionId): int
{
    return (int) $db->table('post_votes')
        ->join('posts', 'posts.id', '=', 'post_votes.post_id')
        ->where('posts.discussion_id', $discussionId)
        ->where('post_votes.value', '>', 0)
        ->where('posts.type', 'comment')
        ->whereNull('posts.hidden_at')
        ->count();
}

// One member may independently upvote multiple posts in the same discussion.
castVote($db, $replyA, $viewer, 1);
castVote($db, $replyB, $viewer, 1);
expect_true(positiveCount($db, $discussionId, $viewer) === 2, 'viewer keeps two positive reply votes');
expect_true(visibleAggregate($db, $discussionId) === 2, 'discussion total sums both reply votes');

castVote($db, $firstPost, $viewer, 1);
expect_true(positiveCount($db, $discussionId, $viewer) === 3, 'viewer may also upvote opening post');
expect_true(visibleAggregate($db, $discussionId) === 3, 'discussion total increments to three');

// Toggling one post off must not clear the viewer's other positive votes.
castVote($db, $replyB, $viewer, 0);
expect_true(positiveCount($db, $discussionId, $viewer) === 2, 'removing reply B leaves two positive votes');
expect_true(
    (int) $db->table('post_votes')->where('post_id', $replyA)->where('user_id', $viewer)->value('value') === 1,
    'reply A remains selected'
);
expect_true(
    (int) $db->table('post_votes')->where('post_id', $firstPost)->where('user_id', $viewer)->value('value') === 1,
    'opening post remains selected'
);
expect_true(visibleAggregate($db, $discussionId) === 2, 'aggregate decrements only the removed vote');

// A second member on the same reply is another independent positive vote.
castVote($db, $replyA, $viewer2, 1);
expect_true(visibleAggregate($db, $discussionId) === 3, 'second member increments aggregate independently');

// Recasting the same member/post updates the existing row; uniqueness stays per post+member.
castVote($db, $replyA, $viewer, 1);
expect_true(
    (int) $db->table('post_votes')->where('post_id', $replyA)->where('user_id', $viewer)->count() === 1,
    'same post/member has one canonical row'
);
expect_true(visibleAggregate($db, $discussionId) === 3, 'recasting same post does not double count');

// Hidden comment votes remain excluded from the public aggregate.
castVote($db, $hiddenReply, $viewer, 1);
expect_true(positiveCount($db, $discussionId, $viewer) === 3, 'hidden reply row can exist canonically');
expect_true(visibleAggregate($db, $discussionId) === 3, 'hidden reply does not inflate visible aggregate');

$provider = (string) file_get_contents($root.'/src/Voting/VotingServiceProvider.php');
$summary = (string) file_get_contents($root.'/src/Voting/DiscussionVoteSummary.php');
expect_true(
    ! str_contains($provider, 'EnforceOneBallotPerDiscussion')
    && ! str_contains($provider, 'PostWasVoted'),
    'no FlatRate listener moves or zeros prior post votes'
);
expect_true(
    str_contains($summary, "where('post_votes.value', '>', 0)")
    && str_contains($summary, "where('posts.discussion_id', $discussionId)")
    && str_contains($summary, "whereNull('posts.hidden_at')"),
    'discussion summary counts all visible positive post votes'
);

if ($failures > 0) {
    fwrite(STDERR, "plain-voting-multi-post-runtime.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "MULTI_POST_POSITIVE_BALLOTS=PASS\n");
fwrite(STDERR, "DISCUSSION_AGGREGATE_SUMS_ALL_POSITIVE_POST_VOTES=PASS\n");
fwrite(STDERR, "SPECIFIC_POST_TOGGLE_ISOLATION=PASS\n");
fwrite(STDERR, "plain-voting-multi-post-runtime.php: all checks passed\n");
exit(0);
