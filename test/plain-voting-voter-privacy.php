<?php

/**
 * GROWTH-001E2 — voter identity serialization privacy gates (no Flarum boot).
 *
 * Covers permission matrix, exception fail-closed, readiness blocker,
 * boot override registration markers, and neutral-row relation semantics.
 */

declare(strict_types=1);

namespace Tobscure\JsonApi {
    if (! class_exists(Relationship::class, false)) {
        class Relationship
        {
            /** @var mixed */
            public $data;

            public function __construct($data = null)
            {
                $this->data = $data;
            }
        }
    }
}

namespace Flarum\Api\Serializer {
    if (! class_exists(BasicUserSerializer::class, false)) {
        class BasicUserSerializer
        {
        }
    }

    if (! class_exists(PostSerializer::class, false)) {
        class PostSerializer
        {
            /** @var object */
            private $actor;

            /** @var array<string, mixed> */
            public array $hasManyCalls = [];

            public function __construct(object $actor)
            {
                $this->actor = $actor;
            }

            public function getActor(): object
            {
                return $this->actor;
            }

            public function hasMany($model, $serializer, $relation = null)
            {
                $this->hasManyCalls[] = [
                    'model' => $model,
                    'serializer' => $serializer,
                    'relation' => $relation,
                ];

                return new \Tobscure\JsonApi\Relationship(['type' => 'users', 'ids' => ['probe']]);
            }
        }
    }
}

namespace Flarum\Discussion {
    if (! class_exists(Discussion::class, false)) {
        class Discussion
        {
            public int $id;

            public function __construct(int $id = 1)
            {
                $this->id = $id;
            }
        }
    }
}

namespace Flarum\Post {
    if (! class_exists(Post::class, false)) {
        class Post
        {
            public int $id;
            public $discussion;
            /** @var array<string, mixed> */
            public array $relations = [];

            public function __construct(int $id = 1, $discussion = null)
            {
                $this->id = $id;
                $this->discussion = $discussion ?? new \Flarum\Discussion\Discussion(1);
            }

            public function __get(string $name)
            {
                return $this->relations[$name] ?? null;
            }
        }
    }
}

namespace Flarum\Group {
    if (! class_exists(Group::class, false)) {
        class Group
        {
            public const GUEST_ID = 2;
            public const MEMBER_ID = 3;
        }
    }
}

namespace Illuminate\Contracts\Container {
    if (! interface_exists(Container::class, false)) {
        interface Container
        {
            public function bound($abstract);

            public function make($abstract);
        }
    }
}

namespace Flarum\Settings {
    if (! interface_exists(SettingsRepositoryInterface::class, false)) {
        interface SettingsRepositoryInterface
        {
            public function get($key, $default = null);
        }
    }
}

namespace Flarum\Extension {
    if (! class_exists(ExtensionManager::class, false)) {
        class ExtensionManager
        {
            public function isEnabled($name): bool
            {
                return false;
            }
        }
    }
}

namespace Illuminate\Database {
    if (! interface_exists(ConnectionInterface::class, false)) {
        interface ConnectionInterface
        {
        }
    }
}

namespace {
    use FlatRate\SupabaseOAuth\Voting\VoterIdentityRelationshipGuard;
    use FlatRate\SupabaseOAuth\Voting\VotingReadiness;
    use Flarum\Api\Serializer\BasicUserSerializer;
    use Flarum\Api\Serializer\PostSerializer;
    use Flarum\Discussion\Discussion;
    use Flarum\Extension\ExtensionManager;
    use Flarum\Post\Post;
    use Flarum\Settings\SettingsRepositoryInterface;
    use Illuminate\Contracts\Container\Container;
    use Illuminate\Database\ConnectionInterface;
    use Tobscure\JsonApi\Relationship;

    $root = dirname(__DIR__);
    $failures = 0;

    $pass = static function (string $label): void {
        echo "[PASS] {$label}\n";
    };
    $fail = static function (string $label, string $detail = '') use (&$failures): void {
        $failures++;
        echo "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
    };
    $assertTrue = static function (bool $cond, string $label) use ($pass, $fail): void {
        $cond ? $pass($label) : $fail($label);
    };
    $assertSame = static function ($expected, $actual, string $label) use ($pass, $fail): void {
        if ($expected === $actual) {
            $pass($label);
        } else {
            $fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
        }
    };

    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'FlatRate\\SupabaseOAuth\\Voting\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $root.'/src/Voting/'.$relative.'.php';
        if (is_file($path)) {
            require $path;
        }
    });

    $actor = static function (bool $disc, bool $post, bool $throw = false): object {
        return new class($disc, $post, $throw) {
            public function __construct(
                private bool $disc,
                private bool $post,
                private bool $throw
            ) {
            }

            public function can(string $ability, $model = null): bool
            {
                if ($this->throw) {
                    throw new RuntimeException('permission evaluation failed');
                }
                if ($ability !== 'canSeeVoters') {
                    return false;
                }
                if ($model instanceof Discussion) {
                    return $this->disc;
                }
                if ($model instanceof Post) {
                    return $this->post;
                }

                return false;
            }
        };
    };

    $guard = new VoterIdentityRelationshipGuard();
    $assertTrue(! $guard->isRegistered(), 'guard starts unregistered');
    $guard->markRegistered();
    $assertTrue($guard->isRegistered(), 'markRegistered sets readiness boolean');

    $post = new Post(10, new Discussion(3));
    $post->relations['upvotes'] = [(object) ['id' => 99]];
    $post->relations['downvotes'] = [(object) ['id' => 88]];

    // Guest / ordinary member hide
    foreach (['guest' => [false, false], 'member' => [false, false]] as $label => [$d, $p]) {
        $ser = new PostSerializer($actor($d, $p));
        foreach (['upvotes', 'downvotes'] as $rel) {
            $assertSame(null, $guard->relationship($ser, $post, $rel), "{$label} {$rel} hidden");
            $assertSame([], $ser->hasManyCalls, "{$label} {$rel} no hasMany");
        }
    }

    // Mixed discussion/post matrix
    foreach ([
        'discT_postF' => [true, false, null],
        'discF_postT' => [false, true, null],
        'discT_postT' => [true, true, true],
    ] as $label => [$d, $p, $expectAvailable]) {
        $ser = new PostSerializer($actor($d, $p));
        $result = $guard->relationship($ser, $post, 'upvotes');
        if ($expectAvailable === null) {
            $assertSame(null, $result, "mixed {$label} hidden");
        } else {
            $assertTrue($result instanceof Relationship, "mixed {$label} available");
            $assertSame(BasicUserSerializer::class, $ser->hasManyCalls[0]['serializer'] ?? null, "mixed {$label} BasicUserSerializer");
            $assertSame('upvotes', $ser->hasManyCalls[0]['relation'] ?? null, "mixed {$label} relation name");
        }
    }

    // Moderator / admin show both
    foreach (['moderator', 'admin'] as $role) {
        $serUp = new PostSerializer($actor(true, true));
        $serDown = new PostSerializer($actor(true, true));
        $assertTrue($guard->relationship($serUp, $post, 'upvotes') instanceof Relationship, "{$role} upvotes audit");
        $assertTrue($guard->relationship($serDown, $post, 'downvotes') instanceof Relationship, "{$role} downvotes audit");
    }

    // Exception fail-closed
    $serThrow = new PostSerializer($actor(true, true, true));
    $assertSame(null, $guard->relationship($serThrow, $post, 'upvotes'), 'exception fail-closed upvotes');
    $assertSame(null, $guard->relationship($serThrow, $post, 'downvotes'), 'exception fail-closed downvotes');

    // Unexpected relationship name
    $serOk = new PostSerializer($actor(true, true));
    $assertSame(null, $guard->relationship($serOk, $post, 'votes'), 'unexpected name fail-closed');

    // Member canSeeVotes but not canSeeVoters: identity still hidden (predicate ignores canSeeVotes)
    $memberVotesOnly = new class {
        public function can(string $ability, $model = null): bool
        {
            return $ability === 'canSeeVotes';
        }
    };
    $serVotesOnly = new PostSerializer($memberVotesOnly);
    $assertSame(null, $guard->relationship($serVotesOnly, $post, 'upvotes'), 'canSeeVotes alone hides identity');

    // Neutral value=0 is not in upvotes/downvotes model filters (FoF relation contract)
    $fofPathCandidates = [
        $root.'/test/fixtures/fof-gamification-1.6.12-vote-relations.excerpt.php',
        $root.'/../vendor/fof/gamification/extend.php',
        '/home/ilove/dev/flatrate-wiki/_scratch-growth001/.work/growth001d-gamification-staged-production-install-r1/phase1-disposable/flarum/vendor/fof/gamification/extend.php',
    ];
    $fofSrc = '';
    foreach ($fofPathCandidates as $cand) {
        if (! is_file($cand)) {
            continue;
        }
        $raw = (string) file_get_contents($cand);
        // Fixture returns a heredoc string; live extend.php is raw PHP source.
        if (str_ends_with($cand, '.excerpt.php')) {
            /** @var string $fofSrc */
            $fofSrc = (string) (include $cand);
        } else {
            $fofSrc = $raw;
        }
        break;
    }
    if ($fofSrc !== '') {
        $assertTrue(
            str_contains($fofSrc, "where('value', '>', 0)") && str_contains($fofSrc, "where('value', -1)"),
            'NEUTRAL_ROW_NOT_IN_UPVOTES/DOWNVOTES (FoF model filter)'
        );
    } else {
        $fail('FoF extend.php not found for neutral-row filter proof');
    }

    // Source: boot override present
    $providerSrc = (string) file_get_contents($root.'/src/Voting/VotingServiceProvider.php');
    $assertTrue(str_contains($providerSrc, 'function boot'), 'VotingServiceProvider::boot present');
    $assertTrue(str_contains($providerSrc, "relationship(\n                'upvotes'")
        || str_contains($providerSrc, "relationship('upvotes'"), 'upvotes relationship override');
    $assertTrue(str_contains($providerSrc, "relationship(\n                'downvotes'")
        || str_contains($providerSrc, "relationship('downvotes'"), 'downvotes relationship override');
    $assertTrue(str_contains($providerSrc, 'markRegistered()'), 'markRegistered after extend');
    $assertTrue(str_contains($providerSrc, 'VoterIdentityRelationshipGuard'), 'guard wired in provider');

    $guardSrc = (string) file_get_contents($root.'/src/Voting/VoterIdentityRelationshipGuard.php');
    $assertTrue(str_contains($guardSrc, "can('canSeeVoters'"), 'permission predicate canSeeVoters');
    $assertTrue(str_contains($guardSrc, 'catch (Throwable'), 'Throwable fail-closed');
    $assertTrue(! preg_match('/^use\\s+FoF\\\\Gamification\\\\/m', $guardSrc), 'guard soft-dep (no FoF import)');

    $readySrc = (string) file_get_contents($root.'/src/Voting/VotingReadiness.php');
    $assertTrue(str_contains($readySrc, 'voter_relationship_guard_registered'), 'readiness privacy field');
    $assertTrue(str_contains($readySrc, 'voter_identity_serializer_guard_unavailable'), 'readiness blocker code');
    $assertTrue(str_contains($readySrc, 'vote_row_count_total'), 'vote_row_count_total');
    $assertTrue(str_contains($readySrc, 'vote_row_count_active'), 'vote_row_count_active');
    $assertTrue(str_contains($readySrc, 'vote_row_count_neutral'), 'vote_row_count_neutral');

    // Readiness: unregistered guard blocks safe_to_enable
    $container = new class implements Container {
        private array $bindings = [];

        public function bound($abstract): bool
        {
            return array_key_exists($abstract, $this->bindings);
        }

        public function make($abstract)
        {
            if (! array_key_exists($abstract, $this->bindings)) {
                throw new RuntimeException('unbound '.$abstract);
            }

            return $this->bindings[$abstract];
        }

        public function singleton(string $abstract, $concrete): void
        {
            $this->bindings[$abstract] = $concrete;
        }
    };
    $unregistered = new VoterIdentityRelationshipGuard();
    $container->singleton(VoterIdentityRelationshipGuard::class, $unregistered);

    $schemaOk = new class {
        public function hasTable($t): bool
        {
            return true;
        }

        public function getColumnListing($t): array
        {
            return VotingReadiness::REQUIRED_VOTE_STATE_COLUMNS;
        }

        public function hasColumn($t, $c): bool
        {
            return true;
        }
    };
    $dbOk = new class($schemaOk) implements ConnectionInterface {
        public function __construct(private $schema)
        {
        }

        public function getSchemaBuilder()
        {
            return $this->schema;
        }

        public function table($t)
        {
            return new class {
                public function count(): int
                {
                    return 2;
                }

                public function where($col, $op = null, $val = null)
                {
                    return new class($op, $val) {
                        public function __construct(private $op, private $val)
                        {
                        }

                        public function count(): int
                        {
                            if ($this->op === '!=' && (int) $this->val === 0) {
                                return 0;
                            }
                            if ($this->op === '=' && (int) $this->val === 0) {
                                return 2;
                            }

                            return 0;
                        }
                    };
                }
            };
        }
    };
    $settings = new class implements SettingsRepositoryInterface {
        public function get($key, $default = null)
        {
            $map = [
                'flatrate-voting.enabled' => '0',
                'fof-gamification.allowSelfVotes' => '0',
                'fof-gamification.autoUpvotePosts' => '0',
                'fof-gamification.firstPostOnly' => '0',
                'fof-gamification.upVotesOnly' => '0',
                'fof-gamification.rateLimit' => '1',
            ];

            return $map[$key] ?? $default;
        }
    };
    $extensions = new class extends ExtensionManager {
        public function __construct()
        {
        }

        public function isEnabled($name): bool
        {
            return $name === 'fof-gamification';
        }
    };

    // Stub FoF listener class presence for provider.class_present
    if (! class_exists('FoF\\Gamification\\Listeners\\SaveVotesToDatabase', false)) {
        eval('namespace FoF\\Gamification\\Listeners; class SaveVotesToDatabase {}');
    }

    $inject = static function (object $obj, array $props): void {
        $ref = new ReflectionClass($obj);
        foreach ($props as $name => $value) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($obj, $value);
        }
    };

    /** @var VotingReadiness $readiness */
    $readiness = (new ReflectionClass(VotingReadiness::class))->newInstanceWithoutConstructor();
    $inject($readiness, [
        'container' => $container,
        'settings' => $settings,
        'extensions' => $extensions,
        'db' => $dbOk,
        'permissionProbe' => static function (int $groupId, string $permission): ?bool {
            return false;
        },
    ]);

    $payload = $readiness->build();
    $assertSame(false, $payload['privacy']['voter_relationship_guard_registered'], 'unregistered reflected in privacy');
    $assertSame(false, $payload['safe_to_enable'], 'unregistered blocks safe_to_enable');
    $assertTrue(
        in_array('voter_identity_serializer_guard_unavailable', $payload['blocking_reasons'], true),
        'voter_identity_serializer_guard_unavailable blocker'
    );
    $assertSame(2, $payload['provider']['vote_row_count_total'], 'vote_row_count_total');
    $assertSame(0, $payload['provider']['vote_row_count_active'], 'vote_row_count_active');
    $assertSame(2, $payload['provider']['vote_row_count_neutral'], 'vote_row_count_neutral');
    $assertTrue(
        ! in_array('vote_row_count_total', $payload['blocking_reasons'], true)
        && ! in_array('residual_votes', $payload['blocking_reasons'], true),
        'total>0 is not a launch blocker'
    );

    $registered = new VoterIdentityRelationshipGuard();
    $registered->markRegistered();
    $container->singleton(VoterIdentityRelationshipGuard::class, $registered);
    $payload2 = $readiness->build();
    $assertSame(true, $payload2['privacy']['voter_relationship_guard_registered'], 'registered reflected');
    $assertSame(true, $payload2['safe_to_enable'], 'registered allows safe_to_enable when other facts safe');
    $assertTrue(
        ! in_array('voter_identity_serializer_guard_unavailable', $payload2['blocking_reasons'], true),
        'blocker cleared when registered'
    );

    // Structural: provider boot uses Extend\\ApiSerializer (load-order contract)
    $assertTrue(str_contains($providerSrc, 'Extend\\ApiSerializer') || str_contains($providerSrc, 'use Flarum\\Extend'), 'uses Extend\\ApiSerializer');
    $assertTrue(is_file($root.'/src/Voting/VotingServiceProvider.php'), 'VotingServiceProvider file present');

    // HTTP include coverage is exercised in disposable harness; source gate documents FoF routes
    $fofOptional = $fofSrc !== '' && str_contains($fofSrc, 'ShowPostController') && str_contains($fofSrc, 'ListPostsController')
        && str_contains($fofSrc, "addOptionalInclude(['upvotes', 'downvotes'])");
    $assertTrue($fofOptional, 'ShowPost/ListPosts optional includes present in FoF 1.6.12');
    if ($fofOptional) {
        $pass('HTTP include=upvotes|downvotes registered on ShowPost+ListPosts');
    }

    echo 'plain-voting-voter-privacy.php: '.($failures === 0 ? 'all checks passed' : "{$failures} failure(s)")."\n";
    exit($failures === 0 ? 0 : 1);
}
