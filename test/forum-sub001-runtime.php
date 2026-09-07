<?php

/**
 * FORUM-SUB-001B disposable runtime harness.
 *
 * Boots real Composer-installed Flarum 1.8.19 / Tags 1.8.8 / FoF Follow Tags 1.3.0
 * classes against an in-memory SQLite schema. Does not touch production.
 *
 * Usage (from companion root, after harness composer install):
 *   php test/forum-sub001-runtime.php
 */

declare(strict_types=1);

use FlatRate\SupabaseOAuth\Subscription\EffectiveTagSubscriptionResolver;
use FlatRate\SupabaseOAuth\Subscription\FamilyAwareNotificationSyncer;
use FlatRate\SupabaseOAuth\Subscription\FamilyUserLookup;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyRecipientEvaluator;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyRecipientResolver;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyServiceProvider;
use FlatRate\SupabaseOAuth\Subscription\TagFamilyRegistry;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use FoF\FollowTags\Notifications\NewDiscussionBlueprint;
use FoF\FollowTags\Notifications\NewPostBlueprint;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;

$harnessDir = __DIR__.'/harness/forum-sub001-runtime';
$autoload = $harnessDir.'/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "RUNTIME_HARNESS_MISSING_VENDOR: run composer install in {$harnessDir}\n");
    exit(2);
}

require $autoload;

$failures = 0;
$companionRoot = dirname(__DIR__);

function pass(string $label): void
{
    echo "[PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

function assertTrue(bool $cond, string $label): void
{
    $cond ? pass($label) : fail($label);
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected === $actual) {
        pass($label);
    } else {
        fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}

/**
 * Minimal SQLite schema for resolver queries.
 */
function bootSqlite(): Capsule
{
    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $schema = $capsule->schema();
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('username')->nullable();
        $table->string('email')->nullable();
        $table->boolean('is_email_confirmed')->default(1);
        $table->timestamps();
    });
    $schema->create('tags', function ($table) {
        $table->increments('id');
        $table->string('slug')->unique();
        $table->string('name')->nullable();
    });
    $schema->create('tag_user', function ($table) {
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('tag_id');
        $table->string('subscription')->nullable();
        $table->primary(['user_id', 'tag_id']);
    });
    $schema->create('discussion_user', function ($table) {
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('discussion_id');
        $table->integer('last_read_post_number')->nullable();
        $table->primary(['user_id', 'discussion_id']);
    });

    return $capsule;
}

function makeUser(int $id, string $username): User
{
    $user = new User();
    $user->forceFill([
        'id' => $id,
        'username' => $username,
        'email' => $username.'@example.test',
        'is_email_confirmed' => 1,
    ]);
    $user->exists = true;

    return $user;
}

function seedTag(int $id, string $slug): void
{
    Capsule::table('tags')->insert(['id' => $id, 'slug' => $slug, 'name' => $slug]);
}

function seedSub(int $userId, int $tagId, ?string $subscription): void
{
    Capsule::table('tag_user')->insert([
        'user_id' => $userId,
        'tag_id' => $tagId,
        'subscription' => $subscription,
    ]);
}

/**
 * Discussion/post doubles with controllable visibility.
 */
function makeDiscussionPost(array $tags, int $discussionId, int $authorId, bool $discussionVisible, bool $postVisible, ?\Throwable $discussionError = null, ?\Throwable $postError = null, ?int $lastReadForUser = null): array
{
    $tagModels = [];
    foreach ($tags as $tag) {
        $tagModels[] = (object) ['id' => $tag['id'], 'slug' => $tag['slug']];
    }

    $discussion = new class($discussionId, $authorId, $tagModels, $discussionVisible, $discussionError, $lastReadForUser) {
        public int $id;
        public int $user_id;
        public array $tags;
        private bool $visible;
        private ?\Throwable $error;
        private ?int $lastRead;

        public function __construct(int $id, int $userId, array $tags, bool $visible, ?\Throwable $error, ?int $lastRead)
        {
            $this->id = $id;
            $this->user_id = $userId;
            $this->tags = $tags;
            $this->visible = $visible;
            $this->error = $error;
            $this->lastRead = $lastRead;
        }

        public function newQuery()
        {
            if ($this->error) {
                throw $this->error;
            }
            $visible = $this->visible;
            $id = $this->id;

            return new class($visible, $id) {
                public function __construct(private bool $visible, private int $id)
                {
                }

                public function whereVisibleTo($user)
                {
                    return $this;
                }

                public function find($findId)
                {
                    return ($this->visible && (int) $findId === $this->id) ? (object) ['id' => $this->id] : null;
                }
            };
        }

        public function stateFor($user): object
        {
            return (object) ['last_read_post_number' => $this->lastRead];
        }
    };

    $post = new class($postVisible, $postError, $authorId) {
        public int $user_id;
        public int $number = 5;
        public $discussion;
        private bool $visible;
        private ?\Throwable $error;

        public function __construct(bool $visible, ?\Throwable $error, int $authorId)
        {
            $this->visible = $visible;
            $this->error = $error;
            $this->user_id = $authorId;
        }

        public function isVisibleTo($user): bool
        {
            if ($this->error) {
                throw $this->error;
            }

            return $this->visible;
        }
    };

    $post->discussion = $discussion;

    return [$discussion, $post];
}

function blueprintWithoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

// ---------------------------------------------------------------------------
// Boot SQLite + container binding
// ---------------------------------------------------------------------------
$capsule = bootSqlite();
$db = $capsule->getConnection();

Container::setInstance(null);
$container = new Container();
Container::setInstance($container);
$container->instance('flarum.db', $db);
$container->instance(ConnectionInterface::class, $db);
$container->instance(Container::class, $container);

// Flarum AbstractServiceProvider expects $app; illuminate Container works as app.
$provider = new FollowTagsFamilyServiceProvider($container);
$provider->register();

$syncer = $container->make(NotificationSyncer::class);
assertTrue($syncer instanceof FamilyAwareNotificationSyncer, 'RUNTIME_NOTIFICATION_SYNCER_BINDING_TEST');

// Seed taxonomy + users
seedTag(1, 'gm');
seedTag(2, 'chevrolet');
seedTag(6, 'cdjr');
seedTag(7, 'jeep');

$author = makeUser(1, 'author');
$gmFollower = makeUser(10, 'gm_follower');
$other = makeUser(11, 'other');
Capsule::table('users')->insert([
    ['id' => 1, 'username' => 'author', 'email' => 'author@example.test', 'is_email_confirmed' => 1],
    ['id' => 10, 'username' => 'gm_follower', 'email' => 'gm_follower@example.test', 'is_email_confirmed' => 1],
    ['id' => 11, 'username' => 'other', 'email' => 'other@example.test', 'is_email_confirmed' => 1],
]);

$registry = $container->make(TagFamilyRegistry::class);
$effective = $container->make(EffectiveTagSubscriptionResolver::class);
$evaluator = $container->make(FollowTagsFamilyRecipientEvaluator::class);
$users = $container->make(FamilyUserLookup::class);

$buildResolver = function () use ($registry, $effective, $evaluator, $db, $users) {
    return new FollowTagsFamilyRecipientResolver($registry, $effective, $evaluator, $db, $users);
};

$chevroletTags = [['id' => 2, 'slug' => 'chevrolet']];

// ---------------------------------------------------------------------------
// Visibility exception paths — fail closed
// ---------------------------------------------------------------------------
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'follow'); // GM=follow

[$discussionThrow, $postOk] = makeDiscussionPost(
    $chevroletTags,
    100,
    1,
    true,
    true,
    new RuntimeException('discussion visibility boom'),
    null
);
$bp = blueprintWithoutConstructor(NewDiscussionBlueprint::class);
$bp->discussion = $discussionThrow;
$bp->post = $postOk;
$out = $buildResolver()->resolve($bp, []);
assertSame(0, count($out), 'VISIBILITY_EXCEPTION discussion throw → not returned');

[$discussionOk, $postThrow] = makeDiscussionPost(
    $chevroletTags,
    101,
    1,
    true,
    true,
    null,
    new RuntimeException('post visibility boom')
);
$bp = blueprintWithoutConstructor(NewDiscussionBlueprint::class);
$bp->discussion = $discussionOk;
$bp->post = $postThrow;
$out = $buildResolver()->resolve($bp, []);
assertSame(0, count($out), 'VISIBILITY_EXCEPTION post throw → not returned');

[$discussionOk2, $postThrow2] = makeDiscussionPost(
    $chevroletTags,
    102,
    1,
    true,
    true,
    null,
    new RuntimeException('reply post visibility boom'),
    4 // caught-up for lastPostNumber=4
);
$bp = blueprintWithoutConstructor(NewPostBlueprint::class);
$bp->post = $postThrow2;
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'lurk');
$out = $buildResolver()->resolve($bp, []);
assertSame(0, count($out), 'VISIBILITY_EXCEPTION reply post throw → not returned');
pass('VISIBILITY_EXCEPTION_RECIPIENT_TEST');

// ---------------------------------------------------------------------------
// RUNTIME_NEW_DISCUSSION_RESOLVER_TEST
// ---------------------------------------------------------------------------
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'follow');
[$discussion, $post] = makeDiscussionPost($chevroletTags, 200, 1, true, true);
$bp = blueprintWithoutConstructor(NewDiscussionBlueprint::class);
$bp->discussion = $discussion;
$bp->post = $post;
$out = $buildResolver()->resolve($bp, []);
assertSame(1, count($out), 'RUNTIME_NEW_DISCUSSION inherited GM follower returned');
assertSame(10, (int) $out[0]->id, 'RUNTIME_NEW_DISCUSSION user id');

// Direct override ignore
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'follow');
seedSub(10, 2, 'ignore');
[$discussion, $post] = makeDiscussionPost($chevroletTags, 201, 1, true, true);
$bp = blueprintWithoutConstructor(NewDiscussionBlueprint::class);
$bp->discussion = $discussion;
$bp->post = $post;
$out = $buildResolver()->resolve($bp, []);
assertSame(0, count($out), 'RUNTIME_DIRECT_OVERRIDE ignore excludes user');

// Dedupe: upstream + inherited same user
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'follow');
seedSub(10, 2, 'follow');
[$discussion, $post] = makeDiscussionPost($chevroletTags, 202, 1, true, true);
$bp = blueprintWithoutConstructor(NewDiscussionBlueprint::class);
$bp->discussion = $discussion;
$bp->post = $post;
$upstream = [$gmFollower, $gmFollower];
$out = $buildResolver()->resolve($bp, $upstream);
assertSame(1, count($out), 'RUNTIME_DEDUPE_TEST single recipient');
assertSame(10, (int) $out[0]->id, 'RUNTIME_DEDUPE user id');
pass('RUNTIME_NEW_DISCUSSION_RESOLVER_TEST');
pass('RUNTIME_DIRECT_OVERRIDE_TEST');
pass('RUNTIME_DEDUPE_TEST');

// ---------------------------------------------------------------------------
// RUNTIME_NEW_POST_RESOLVER_TEST
// ---------------------------------------------------------------------------
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'lurk');
[$discussion, $post] = makeDiscussionPost($chevroletTags, 300, 1, true, true, null, null, 4);
$bp = blueprintWithoutConstructor(NewPostBlueprint::class);
$bp->post = $post;
$out = $buildResolver()->resolve($bp, []);
assertSame(1, count($out), 'RUNTIME_NEW_POST inherited lurk returned');

Capsule::table('tag_user')->delete();
seedSub(10, 1, 'lurk');
seedSub(10, 2, 'follow'); // direct follow overrides → no reply notify
[$discussion, $post] = makeDiscussionPost($chevroletTags, 301, 1, true, true, null, null, 4);
$bp = blueprintWithoutConstructor(NewPostBlueprint::class);
$bp->post = $post;
$out = $buildResolver()->resolve($bp, []);
assertSame(0, count($out), 'RUNTIME_NEW_POST direct follow override → not returned');
pass('RUNTIME_NEW_POST_RESOLVER_TEST');

// ---------------------------------------------------------------------------
// Parent sync receives final array (runtime evidence)
// ---------------------------------------------------------------------------
Capsule::table('tag_user')->delete();
seedSub(10, 1, 'follow');
[$discussion, $post] = makeDiscussionPost($chevroletTags, 400, 1, true, true);
$bp = blueprintWithoutConstructor(NewDiscussionBlueprint::class);
$bp->discussion = $discussion;
$bp->post = $post;

$capturing = new class($buildResolver()) extends FamilyAwareNotificationSyncer {
    /** @var list<User> */
    public array $parentUsers = [];

    protected function syncWithParent(BlueprintInterface $blueprint, array $users): void
    {
        $this->parentUsers = array_values($users);
        // Intentionally do not call real NotificationSyncer::sync (no notifications table).
    }
};

$capturing->sync($bp, [$gmFollower, $gmFollower]);
assertSame(1, count($capturing->parentUsers), 'FAMILY_RECIPIENT_RESOLUTION_HAPPENS_BEFORE_PARENT_SYNC count');
assertSame(10, (int) $capturing->parentUsers[0]->id, 'FAMILY_RECIPIENT_RESOLUTION_HAPPENS_BEFORE_PARENT_SYNC id');
assertTrue(!str_contains(
    file_get_contents($companionRoot.'/src/Subscription/FamilyAwareNotificationSyncer.php'),
    'beforeSending('
), 'BEFORE_SENDING_USED_TO_ADD_FAMILY_RECIPIENTS=false');

// Fail-closed source markers
$resolverSrc = file_get_contents($companionRoot.'/src/Subscription/FollowTagsFamilyRecipientResolver.php');
assertTrue(str_contains($resolverSrc, 'return false;'), 'visibility helpers fail closed');
assertTrue(
    preg_match('/\$discussionVisible\s*=\s*false/', $resolverSrc) === 1
    && preg_match('/\$postVisible\s*=\s*false/', $resolverSrc) === 1,
    'DISCUSSION_VISIBILITY_EXCEPTION_FAILS_CLOSED defaults'
);
assertTrue(!preg_match('/catch\s*\(\s*\\\\?Throwable\s*\)\s*\{\s*\$discussionVisible\s*=\s*true/', $resolverSrc), 'no discussion fail-open catch');
assertTrue(!preg_match('/catch\s*\(\s*\\\\?Throwable\s*\)\s*\{\s*\$postVisible\s*=\s*true/', $resolverSrc), 'no post fail-open catch');

if ($failures > 0) {
    fwrite(STDERR, "forum-sub001-runtime.php: {$failures} failure(s)\n");
    exit(1);
}

echo "forum-sub001-runtime.php: all checks passed\n";
echo "VISIBILITY_EXCEPTION_RECIPIENT_TEST=PASS\n";
echo "RUNTIME_NOTIFICATION_SYNCER_BINDING_TEST=PASS\n";
echo "RUNTIME_NEW_DISCUSSION_RESOLVER_TEST=PASS\n";
echo "RUNTIME_NEW_POST_RESOLVER_TEST=PASS\n";
echo "RUNTIME_DIRECT_OVERRIDE_TEST=PASS\n";
echo "RUNTIME_DEDUPE_TEST=PASS\n";
echo "DISCUSSION_VISIBILITY_EXCEPTION_FAILS_CLOSED=true\n";
echo "POST_VISIBILITY_EXCEPTION_FAILS_CLOSED=true\n";
echo "FAMILY_RECIPIENT_RESOLUTION_HAPPENS_BEFORE_PARENT_SYNC=true\n";
echo "BEFORE_SENDING_USED_TO_ADD_FAMILY_RECIPIENTS=false\n";
