<?php

/**
 * GROWTH-001UI — canonical one-ballot + concurrency + previous-author points.
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
    fwrite(STDERR, "PLAYWRIGHT_LOCAL=INFRA_BLOCKED\n");
    fwrite(STDERR, "CANONICAL_ONE_POSITIVE_BALLOT=INFRA_BLOCKED\n");
    exit(0);
}

require $harnessVendor;

spl_autoload_register(function ($class) use ($root) {
    $prefix = 'FlatRate\\SupabaseOAuth\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root.'/src/'.$rel.'.php';
    if (is_file($file)) {
        require $file;
    }
});

use FlatRate\SupabaseOAuth\Voting\EnforceOneBallotPerDiscussion;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\NullLogger;

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
        'prefix' => 'pv_ballot_',
    ];
} elseif (file_exists($socket)) {
    // Ensure DB exists before Capsule connects to it.
    // Note: unix sockets are not regular files, so use file_exists() not is_file().
    $mysqli = @new mysqli('localhost', 'root', '', null, null, $socket);
    if ($mysqli->connect_errno) {
        fwrite(STDERR, "[SKIP] MariaDB socket present but connect failed: {$mysqli->connect_error}\n");
        exit(0);
    }
    $mysqli->query('CREATE DATABASE IF NOT EXISTS flarum_ballot_r3');
    $mysqli->close();

    $config = [
        'driver' => 'mysql',
        'unix_socket' => $socket,
        'database' => 'flarum_ballot_r3',
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
foreach (['rank_users', 'ranks', 'post_votes', 'posts', 'discussions', 'users'] as $table) {
    $schema->dropIfExists($table);
}

$schema->create('users', function ($t) {
    $t->increments('id');
    $t->string('username')->nullable();
    $t->integer('votes')->default(0);
});
$schema->create('discussions', function ($t) {
    $t->increments('id');
    $t->integer('first_post_id')->nullable();
    $t->integer('votes')->default(0);
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
$schema->create('ranks', function ($t) {
    $t->increments('id');
    $t->string('name');
    $t->string('color')->nullable();
    $t->integer('points')->default(0);
});
$schema->create('rank_users', function ($t) {
    $t->integer('user_id');
    $t->integer('rank_id');
    $t->primary(['user_id', 'rank_id']);
});

// Seed actors / authors / ranks.
$viewerId = $db->table('users')->insertGetId(['username' => 'viewer', 'votes' => 0]);
$authorA = $db->table('users')->insertGetId(['username' => 'author_a', 'votes' => 0]);
$authorB = $db->table('users')->insertGetId(['username' => 'author_b', 'votes' => 0]);
$rankBronze = $db->table('ranks')->insertGetId(['name' => 'bronze', 'color' => '#cd7f32', 'points' => 1]);
$db->table('ranks')->insertGetId(['name' => 'silver', 'color' => '#c0c0c0', 'points' => 5]);

$discussionId = $db->table('discussions')->insertGetId(['first_post_id' => null, 'votes' => 0]);
$firstPost = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $authorA,
    'type' => 'comment',
    'hidden_at' => null,
]);
$replyA = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $authorA,
    'type' => 'comment',
    'hidden_at' => null,
]);
$replyB = $db->table('posts')->insertGetId([
    'discussion_id' => $discussionId,
    'user_id' => $authorB,
    'type' => 'comment',
    'hidden_at' => null,
]);
$db->table('discussions')->where('id', $discussionId)->update(['first_post_id' => $firstPost]);

// reconcile() does not consult the gate; null is enough for this suite.
$enforcer = new EnforceOneBallotPerDiscussion($db, null, new NullLogger());

function positiveCount($db, int $discussionId, int $userId): int
{
    return (int) $db->table('post_votes')
        ->join('posts', 'posts.id', '=', 'post_votes.post_id')
        ->where('posts.discussion_id', $discussionId)
        ->where('post_votes.user_id', $userId)
        ->where('post_votes.value', '>', 0)
        ->count();
}

function castVote($db, int $postId, int $userId, int $value): void
{
    $now = date('Y-m-d H:i:s');
    $row = $db->table('post_votes')->where('post_id', $postId)->where('user_id', $userId)->first();
    if ($row) {
        $db->table('post_votes')->where('id', $row->id)->update(['value' => $value, 'updated_at' => $now]);
    } else {
        $db->table('post_votes')->insert([
            'post_id' => $postId,
            'user_id' => $userId,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function aggregate($db, int $discussionId): int
{
    return (int) $db->table('post_votes')
        ->join('posts', 'posts.id', '=', 'post_votes.post_id')
        ->where('posts.discussion_id', $discussionId)
        ->where('post_votes.value', '>', 0)
        ->where('posts.type', 'comment')
        ->whereNull('posts.hidden_at')
        ->count();
}

// --- Scenario: first post vote ---
castVote($db, $firstPost, $viewerId, 1);
$enforcer->reconcile($discussionId, $viewerId, $firstPost);
expect_true(positiveCount($db, $discussionId, $viewerId) === 1, 'CANONICAL after first vote = 1');
expect_true(aggregate($db, $discussionId) === 1, 'AGGREGATE after first vote = 1');

// --- Move first -> reply A ---
castVote($db, $replyA, $viewerId, 1);
$enforcer->reconcile($discussionId, $viewerId, $replyA);
expect_true(positiveCount($db, $discussionId, $viewerId) === 1, 'CANONICAL after move to reply A = 1');
expect_true(aggregate($db, $discussionId) === 1, 'AGGREGATE stable after first->replyA');
$keep = $db->table('post_votes')->where('user_id', $viewerId)->where('value', '>', 0)->first();
expect_true($keep && (int) $keep->post_id === $replyA, 'KEEP target is reply A');

// Author A should have cached +1 (owns reply A).
$votesA = (int) $db->table('users')->where('id', $authorA)->value('votes');
expect_true($votesA === 1, 'PREVIOUS/target author A points = 1 after landing on reply A');
$rankA = $db->table('rank_users')->where('user_id', $authorA)->pluck('rank_id')->all();
expect_true(in_array($rankBronze, array_map('intval', $rankA), true), 'Author A ranks include bronze');

// --- Move reply A -> reply B (previous author must lose point) ---
castVote($db, $replyB, $viewerId, 1);
$enforcer->reconcile($discussionId, $viewerId, $replyB);
expect_true(positiveCount($db, $discussionId, $viewerId) === 1, 'CANONICAL after move to reply B = 1');
expect_true(aggregate($db, $discussionId) === 1, 'AGGREGATE_STABLE_DURING_MOVE');
$votesA = (int) $db->table('users')->where('id', $authorA)->value('votes');
$votesB = (int) $db->table('users')->where('id', $authorB)->value('votes');
expect_true($votesA === 0, 'PREVIOUS_AUTHOR_POINTS_RECALCULATED (A back to 0)');
expect_true($votesB === 1, 'NEW author B points = 1');
$rankA = $db->table('rank_users')->where('user_id', $authorA)->pluck('rank_id')->all();
$rankB = $db->table('rank_users')->where('user_id', $authorB)->pluck('rank_id')->all();
expect_true($rankA === [], 'PREVIOUS_AUTHOR_RANKS_RECALCULATED (A cleared)');
expect_true(in_array($rankBronze, array_map('intval', $rankB), true), 'Author B ranks include bronze');

// --- Removal ---
castVote($db, $replyB, $viewerId, 0);
// FoF removal does not call our enforcer for value<=0; simulate post-remove author repair
$db->table('users')->where('id', $authorB)->update(['votes' => 0]);
$db->table('rank_users')->where('user_id', $authorB)->delete();
expect_true(positiveCount($db, $discussionId, $viewerId) === 0, 'CANONICAL after removal = 0');
expect_true(aggregate($db, $discussionId) === 0, 'AGGREGATE_DECREMENTS_ON_REMOVE');

// --- Two members ---
$viewer2 = $db->table('users')->insertGetId(['username' => 'viewer2', 'votes' => 0]);
castVote($db, $replyA, $viewerId, 1);
$enforcer->reconcile($discussionId, $viewerId, $replyA);
castVote($db, $replyB, $viewer2, 1);
$enforcer->reconcile($discussionId, $viewer2, $replyB);
expect_true(aggregate($db, $discussionId) === 2, 'two members => aggregate 2');
expect_true(positiveCount($db, $discussionId, $viewerId) === 1, 'member1 still one ballot');
expect_true(positiveCount($db, $discussionId, $viewer2) === 1, 'member2 still one ballot');

// --- Concurrent double-write simulation ---
$db->table('post_votes')->where('user_id', $viewerId)->delete();
castVote($db, $replyA, $viewerId, 1);
castVote($db, $replyB, $viewerId, 1);
expect_true(positiveCount($db, $discussionId, $viewerId) === 2, 'precondition: planted double ballot');

$errors = [];
$run = function (int $keep) use ($enforcer, $discussionId, $viewerId, &$errors) {
    try {
        $enforcer->reconcile($discussionId, $viewerId, $keep);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
};

$run($replyA);
$run($replyB);
$count = positiveCount($db, $discussionId, $viewerId);
expect_true($count <= 1, 'CONCURRENT_DOUBLE_BALLOT_GUARD terminal <= 1');
expect_true($count === 1, 'CONCURRENT_DOUBLE_BALLOT_GUARD retains one winner');
expect_true($errors === [], 'CONCURRENT_DOUBLE_BALLOT_GUARD no exceptions');

// Source contract: frontend never uses discussion.votes for aggregate.
$pv = (string) file_get_contents($root.'/js/dist/plain-voting.js');
expect_true(
    str_contains($pv, "attribute('flatRateDiscussionUpvotes')")
    && ! preg_match("/FlatRateDiscussionVote[\s\S]{0,800}discussion\.votes\(/", $pv),
    'FOF_DISCUSSION_VOTES_LEAK=false'
);
expect_true(
    str_contains($pv, 'FlatRateDiscussionVote--available')
    && str_contains($pv, 'FlatRateDiscussionVote--mine')
    && str_contains($pv, "save([true, false, 'vote'])"),
    'HEADER_STATE_CONTRACT source'
);

if ($failures > 0) {
    fwrite(STDERR, "plain-voting-one-ballot-runtime.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "CANONICAL_ONE_POSITIVE_BALLOT=PASS\n");
fwrite(STDERR, "CONCURRENT_DOUBLE_BALLOT_GUARD=PASS\n");
fwrite(STDERR, "PREVIOUS_AUTHOR_POINTS_RECALCULATED=PASS\n");
fwrite(STDERR, "PREVIOUS_AUTHOR_RANKS_RECALCULATED=PASS\n");
fwrite(STDERR, "plain-voting-one-ballot-runtime.php: all checks passed\n");
exit(0);
