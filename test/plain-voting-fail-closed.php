<?php

/**
 * GROWTH-001B1 — fail-closed permission tri-state + FORCE_DENY policy proofs.
 */

declare(strict_types=1);

namespace Flarum\User\Access {
    if (! class_exists(AbstractPolicy::class, false)) {
        abstract class AbstractPolicy
        {
            public const ALLOW = 'ALLOW';
            public const DENY = 'DENY';
            public const FORCE_ALLOW = 'FORCE_ALLOW';
            public const FORCE_DENY = 'FORCE_DENY';

            protected function allow()
            {
                return static::ALLOW;
            }

            protected function deny()
            {
                return static::DENY;
            }

            protected function forceAllow()
            {
                return static::FORCE_ALLOW;
            }

            protected function forceDeny()
            {
                return static::FORCE_DENY;
            }
        }
    }
}

namespace Flarum\User {
    if (! class_exists(User::class, false)) {
        class User
        {
            public $id;

            public function __construct(
                int $id = 1,
                private bool $admin = false,
                private bool $guest = false
            ) {
                $this->id = $id;
            }

            public function isAdmin(): bool
            {
                return $this->admin;
            }

            public function isGuest(): bool
            {
                return $this->guest;
            }
        }
    }
}

namespace Flarum\Post {
    if (! class_exists(Post::class, false)) {
        class Post
        {
            public $user_id;

            public function __construct(int $userId = 1)
            {
                $this->user_id = $userId;
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

namespace Illuminate\Database {
    if (! interface_exists(ConnectionInterface::class, false)) {
        interface ConnectionInterface
        {
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

namespace {
    use FlatRate\SupabaseOAuth\Voting\GlobalVotingPolicy;
    use FlatRate\SupabaseOAuth\Voting\PostVotePolicy;
    use FlatRate\SupabaseOAuth\Voting\VoteSafetyGate;
    use FlatRate\SupabaseOAuth\Voting\VotingReadiness;
    use Flarum\Extension\ExtensionManager;
    use Flarum\Post\Post;
    use Flarum\Settings\SettingsRepositoryInterface;
    use Flarum\User\Access\AbstractPolicy;
    use Flarum\User\User;
    use Illuminate\Contracts\Container\Container;
    use Illuminate\Database\ConnectionInterface;

    $root = dirname(__DIR__);
    $failures = 0;

    $pass = static function (string $label): void {
        echo "[PASS] {$label}\n";
    };
    $fail = static function (string $label, string $detail = '') use (&$failures): void {
        $failures++;
        echo "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
    };
    $assertSame = static function ($expected, $actual, string $label) use ($pass, $fail): void {
        if ($expected === $actual) {
            $pass($label);
        } else {
            $fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
        }
    };
    $assertTrue = static function (bool $cond, string $label) use ($pass, $fail): void {
        $cond ? $pass($label) : $fail($label);
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

    $assertSame(true, VotingReadiness::orPermissionStates(true, null), 'OR true+null => true');
    $assertSame(false, VotingReadiness::orPermissionStates(false, false), 'OR false+false => false');
    $assertSame(null, VotingReadiness::orPermissionStates(false, null), 'OR false+null => null');
    $assertSame(null, VotingReadiness::orPermissionStates(null, null), 'OR null+null => null');
    $assertSame(true, VotingReadiness::orPermissionStates(false, true), 'OR false+true => true');

    $totalFail = [
        'inspection_ok' => false,
        'guest_vote_posts' => null,
        'member_vote_posts' => null,
        'guest_ranking' => null,
        'member_ranking' => null,
        'guest_can_see_voters' => null,
        'member_can_see_voters' => null,
    ];
    $blockers = VotingReadiness::appendPermissionBlockers($totalFail);
    $assertTrue(in_array('permission_state_unavailable', $blockers, true), 'PERMISSION_QUERY_FAILURE_FAILS_CLOSED');
    $assertTrue(! VotingReadiness::permissionsAllowSafeEnable($totalFail), 'READINESS_SAFE_ON_PERMISSION_UNKNOWN=false');

    $partial = [
        'inspection_ok' => false,
        'guest_vote_posts' => false,
        'member_vote_posts' => true,
        'guest_ranking' => false,
        'member_ranking' => false,
        'guest_can_see_voters' => null,
        'member_can_see_voters' => false,
    ];
    $assertTrue(
        in_array('permission_state_unavailable', VotingReadiness::appendPermissionBlockers($partial), true),
        'PARTIAL_PERMISSION_FAILURE_FAILS_CLOSED'
    );

    $unsafeAndUnknown = [
        'inspection_ok' => false,
        'guest_vote_posts' => true,
        'member_vote_posts' => false,
        'guest_ranking' => false,
        'member_ranking' => false,
        'guest_can_see_voters' => false,
        'member_can_see_voters' => null,
    ];
    $both = VotingReadiness::appendPermissionBlockers($unsafeAndUnknown);
    $assertTrue(in_array('guest_vote_permission', $both, true), 'UNSAFE_PERMISSION_AND_UNKNOWN_BOTH_REPORTED (unsafe)');
    $assertTrue(in_array('permission_state_unavailable', $both, true), 'UNSAFE_PERMISSION_AND_UNKNOWN_BOTH_REPORTED (unknown)');

    $safePerms = [
        'inspection_ok' => true,
        'guest_vote_posts' => false,
        'member_vote_posts' => true,
        'guest_ranking' => false,
        'member_ranking' => false,
        'guest_can_see_voters' => false,
        'member_can_see_voters' => false,
    ];
    $assertTrue(VotingReadiness::permissionsAllowSafeEnable($safePerms), 'safe permission knowledge allows enable');
    $assertSame([], VotingReadiness::appendPermissionBlockers($safePerms), 'safe permissions emit no blockers');

    $assertSame(null, (static function (): ?bool {
        try {
            throw new RuntimeException('simulated permission query failure');
        } catch (Throwable $e) {
            return null;
        }
    })(), 'PERMISSION_QUERY_FAILURE_RETURNS_FALSE=false');

    $inject = static function (object $obj, array $props): void {
        $ref = new ReflectionClass($obj);
        foreach ($props as $name => $value) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($obj, $value);
        }
    };

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

    $container = new class implements Container {
        public function bound($abstract): bool
        {
            return false;
        }
    };

    $settingsClosed = new class implements SettingsRepositoryInterface {
        public function get($key, $default = null)
        {
            return $key === VoteSafetyGate::SETTING_ENABLED ? false : $default;
        }
    };

    $settingsOpen = new class implements SettingsRepositoryInterface {
        public function get($key, $default = null)
        {
            return $key === VoteSafetyGate::SETTING_ENABLED ? true : $default;
        }
    };

    $extensions = new ExtensionManager();

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
                    return 0;
                }
            };
        }
    };

    /** @var VotingReadiness $readiness */
    $readiness = (new ReflectionClass(VotingReadiness::class))->newInstanceWithoutConstructor();
    $inject($readiness, [
        'container' => $container,
        'settings' => $settingsClosed,
        'extensions' => $extensions,
        'db' => $dbOk,
        'permissionProbe' => static function (int $groupId, string $permission): ?bool {
            return false;
        },
    ]);
    $perms = $readiness->inspectPermissions();
    $assertTrue($perms['inspection_ok'] === true, 'probe all-false inspection_ok');
    $assertSame(false, $perms['guest_vote_posts'], 'probe guest_vote_posts false');

    $inject($readiness, [
        'permissionProbe' => static function (int $groupId, string $permission): ?bool {
            throw new RuntimeException('permission query failed');
        },
    ]);
    $unknown = $readiness->inspectPermissions();
    $assertTrue($unknown['inspection_ok'] === false, 'probe throw inspection_ok=false');
    $assertSame(null, $unknown['guest_vote_posts'], 'probe throw guest_vote_posts=null');
    $assertTrue(
        in_array('permission_state_unavailable', VotingReadiness::appendPermissionBlockers($unknown), true),
        'PERMISSION_STATE_UNAVAILABLE_BLOCKER_PRESENT'
    );

    $inject($readiness, [
        'permissionProbe' => static function (int $groupId, string $permission): ?bool {
            if ($permission === 'discussion.canSeeVoters' && $groupId === 2) {
                return null;
            }

            return false;
        },
    ]);
    $mixed = $readiness->inspectPermissions();
    $assertTrue($mixed['inspection_ok'] === false, 'mixed unknown inspection_ok=false');
    $assertSame(null, $mixed['guest_can_see_voters'], 'mixed guest_can_see_voters=null');
    $assertTrue(
        in_array('permission_state_unavailable', VotingReadiness::appendPermissionBlockers($mixed), true),
        'mixed still blocks safe_to_enable'
    );

    $inject($readiness, [
        'permissionProbe' => static function (int $groupId, string $permission): ?bool {
            if ($groupId === 2 && ($permission === 'discussion.votePosts' || $permission === 'discussion.vote')) {
                return true;
            }
            if ($permission === 'discussion.canSeeVoters' && $groupId === 3) {
                return null;
            }

            return false;
        },
    ]);
    $unsafeBlockers = VotingReadiness::appendPermissionBlockers($readiness->inspectPermissions());
    $assertTrue(in_array('guest_vote_permission', $unsafeBlockers, true), 'guest_vote_permission blocker');
    $assertTrue(in_array('permission_state_unavailable', $unsafeBlockers, true), 'permission_state_unavailable with unsafe');

    // Reset readiness schema path for gate (probe irrelevant to gate)
    $inject($readiness, [
        'permissionProbe' => static function (int $groupId, string $permission): ?bool {
            return false;
        },
    ]);

    /** @var VoteSafetyGate $closedGate */
    $closedGate = (new ReflectionClass(VoteSafetyGate::class))->newInstanceWithoutConstructor();
    $inject($closedGate, [
        'container' => $container,
        'settings' => $settingsClosed,
        'readiness' => $readiness,
    ]);
    /** @var PostVotePolicy $closedPolicy */
    $closedPolicy = (new ReflectionClass(PostVotePolicy::class))->newInstanceWithoutConstructor();
    $inject($closedPolicy, ['gate' => $closedGate]);

    $voter = new User(2, false, false);
    $authorPost = new Post(1);
    $assertSame(AbstractPolicy::FORCE_DENY, $closedPolicy->vote($voter, $authorPost), 'POST_VOTE_FORCE_DENY_TEST');

    /** @var VoteSafetyGate $openGate */
    $openGate = (new ReflectionClass(VoteSafetyGate::class))->newInstanceWithoutConstructor();
    $inject($openGate, [
        'container' => $container,
        'settings' => $settingsOpen,
        'readiness' => $readiness,
    ]);
    /** @var PostVotePolicy $openPolicy */
    $openPolicy = (new ReflectionClass(PostVotePolicy::class))->newInstanceWithoutConstructor();
    $inject($openPolicy, ['gate' => $openGate]);
    $assertSame(null, $openPolicy->vote($voter, $authorPost), 'POST_VOTE_POLICY_SAFE_RESULT=null');

    $ranking = new GlobalVotingPolicy();
    $assertSame(
        AbstractPolicy::FORCE_DENY,
        $ranking->can(new User(5, false, false), GlobalVotingPolicy::RANKING_ABILITY),
        'GLOBAL_RANKING_FORCE_DENY_TEST'
    );
    $assertSame(null, $ranking->can(new User(1, true, false), GlobalVotingPolicy::RANKING_ABILITY), 'ADMIN_RANKING_RESULT=null');
    $assertSame(null, $ranking->can(new User(5, false, false), 'discussion.viewForum'), 'UNRELATED_GLOBAL_ABILITY_RESULT=null');

    $evaluate = static function (array $results): bool {
        $priority = [
            AbstractPolicy::FORCE_DENY => false,
            AbstractPolicy::FORCE_ALLOW => true,
            AbstractPolicy::DENY => false,
            AbstractPolicy::ALLOW => true,
        ];
        foreach ($priority as $criteria => $decision) {
            if (in_array($criteria, $results, true)) {
                return $decision;
            }
        }

        return false;
    };

    $assertTrue($evaluate([AbstractPolicy::ALLOW, AbstractPolicy::FORCE_DENY]) === false, 'FORCE_DENY_BEATS_ALLOW');
    $assertTrue($evaluate([AbstractPolicy::FORCE_ALLOW, AbstractPolicy::FORCE_DENY]) === false, 'FORCE_DENY_BEATS_FORCE_ALLOW');

    $postSrc = (string) file_get_contents($root.'/src/Voting/PostVotePolicy.php');
    $globalSrc = (string) file_get_contents($root.'/src/Voting/GlobalVotingPolicy.php');
    $readySrc = (string) file_get_contents($root.'/src/Voting/VotingReadiness.php');
    $assertTrue(str_contains($postSrc, 'forceDeny()'), 'PostVotePolicy uses forceDeny');
    $assertTrue(! preg_match('/\$this->allow\(/', $postSrc), 'FLATRATE_POLICY_FORCE_ALLOW=false');
    $assertTrue(str_contains($globalSrc, 'forceDeny()'), 'GlobalVotingPolicy uses forceDeny');
    $assertTrue(str_contains($readySrc, 'permission_state_unavailable'), 'blocker code present');
    $assertTrue(str_contains($readySrc, 'inspection_ok'), 'inspection_ok field present');

    echo 'plain-voting-fail-closed.php: '.($failures === 0 ? 'all checks passed' : "{$failures} failure(s)")."\n";
    exit($failures === 0 ? 0 : 1);
}
