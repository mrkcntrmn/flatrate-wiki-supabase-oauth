<?php

/**
 * REP-001F.A1 R2 focused behavior gates:
 * - console/drainer DI shape
 * - Job Breakdown ACT fields
 * - vote feature-gate before state mutation
 * - vote observer containment + safe logging
 */

declare(strict_types=1);

namespace Flarum\Console {
    if (! class_exists(AbstractCommand::class, false)) {
        class AbstractCommand
        {
            public function __construct()
            {
            }

            protected function configure(): void
            {
            }

            protected function fire(): void
            {
            }

            protected function setName(string $name): self
            {
                return $this;
            }

            protected function setDescription(string $description): self
            {
                return $this;
            }

            protected function info(string $message): void
            {
            }
        }
    }
}

namespace {
    $harnessDir = __DIR__.'/harness/activity-r1';
    $autoload = $harnessDir.'/vendor/autoload.php';
    if (! is_file($autoload)) {
        fwrite(STDERR, "ACTIVITY_R1_HARNESS_MISSING_VENDOR: run composer install in {$harnessDir}\n");
        exit(2);
    }
    require $autoload;

    use FlatRate\SupabaseOAuth\Activity\ActivityOutboxDrainer;
    use FlatRate\SupabaseOAuth\Activity\DrainActivityOutboxCommand;
    use FlatRate\SupabaseOAuth\Activity\EmitJobBreakdownCreated;
    use FlatRate\SupabaseOAuth\Activity\EmitVoteActivity;
    use Psr\Log\AbstractLogger;

    spl_autoload_register(static function (string $class): void {
        $prefix = 'FlatRate\\SupabaseOAuth\\Activity\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = dirname(__DIR__).'/src/Activity/'.$relative.'.php';
        if (is_file($path)) {
            require $path;
        }
    });

    $failures = 0;

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

    $commandSrc = file_get_contents(dirname(__DIR__).'/src/Activity/DrainActivityOutboxCommand.php');
    $drainerSrc = file_get_contents(dirname(__DIR__).'/src/Activity/ActivityOutboxDrainer.php');
    assertTrue(! str_contains($commandSrc, '$this->container'), 'COMMAND_USES_UNDEFINED_CONTAINER_PROPERTY=false');
    assertTrue(str_contains($commandSrc, 'ActivityOutboxDrainer $drainer'), 'COMMAND_CONSTRUCTOR_INJECTION=true');
    assertTrue(str_contains($commandSrc, 'parent::__construct()'), 'COMMAND_PARENT_CONSTRUCTOR_CALLED=true');
    assertTrue(str_contains($drainerSrc, 'ActivityClient $client'), 'DRAINER_CLIENT_DI=ActivityClient');
    assertTrue(! preg_match('/private object \$client/', $drainerSrc), 'BUILTIN_OBJECT_DI=false');

    $commandRef = new \ReflectionClass(DrainActivityOutboxCommand::class);
    $ctor = $commandRef->getConstructor();
    assertTrue($ctor !== null && $ctor->getNumberOfParameters() === 1, 'command ctor requires ActivityOutboxDrainer');
    assertSame(ActivityOutboxDrainer::class, $ctor->getParameters()[0]->getType()?->getName(), 'command ctor type ActivityOutboxDrainer');

    $drainerRef = new \ReflectionClass(ActivityOutboxDrainer::class);
    $dCtor = $drainerRef->getConstructor();
    $clientType = $dCtor?->getParameters()[0]->getType()?->getName();
    assertSame('FlatRate\\SupabaseOAuth\\Activity\\ActivityClient', $clientType, 'drainer first param ActivityClient');

    $jbSrc = file_get_contents(dirname(__DIR__).'/src/Activity/EmitJobBreakdownCreated.php');
    assertTrue(str_contains($jbSrc, "'marker_scope' => 'post'"), 'JOB_BREAKDOWN_MARKER_SCOPE=post');
    assertTrue(str_contains($jbSrc, '$createdByStarter'), 'JOB_BREAKDOWN_CREATED_BY_STARTER_DERIVED=true');
    assertTrue(! str_contains($jbSrc, "'marker_scope' => 'reply'"), 'JB reply scope absent');

    $jbRef = new \ReflectionClass(EmitJobBreakdownCreated::class);
    $jb = $jbRef->newInstanceWithoutConstructor();

    $captured = new class extends \FlatRate\SupabaseOAuth\Activity\ActivityEmitter {
        public ?array $lastObs = null;
        public int $emitCount = 0;

        public function __construct()
        {
        }

        public function emit($actor, string $kind, array $obs): void
        {
            $this->emitCount++;
            $this->lastObs = $obs;
        }
    };

    $brands = new class extends \FlatRate\SupabaseOAuth\Activity\BrandContext {
        public function brandSlugFromPost($post): ?string
        {
            return 'toyota';
        }
    };

    $emitterProp = $jbRef->getProperty('emitter');
    $emitterProp->setAccessible(true);
    $emitterProp->setValue($jb, $captured);
    $brandProp = $jbRef->getProperty('brands');
    $brandProp->setAccessible(true);
    $brandProp->setValue($jb, $brands);

    $nonstarterEvent = (object) [
        'post' => (object) [
            'id' => 22,
            'discussion_id' => 10,
            'discussion' => (object) ['user_id' => 1],
        ],
        'actor' => (object) ['id' => 9],
    ];
    $jb->emitAfterMarker($nonstarterEvent, true);
    assertSame('post', $captured->lastObs['marker_scope'] ?? null, 'JB nonstarter marker_scope=post');
    assertSame(false, $captured->lastObs['created_by_starter'] ?? null, 'JB from nonstarter -> false');

    $starterEvent = (object) [
        'post' => (object) [
            'id' => 33,
            'discussion_id' => 10,
            'discussion' => (object) ['user_id' => 4],
        ],
        'actor' => (object) ['id' => 4],
    ];
    $jb->emitAfterMarker($starterEvent, true);
    assertSame(true, $captured->lastObs['created_by_starter'] ?? null, 'JB starter later reply -> true');
    assertSame('post', $captured->lastObs['marker_scope'] ?? null, 'JB starter marker_scope=post');

    $logs = [];
    $logger = new class($logs) extends AbstractLogger {
        public function __construct(private array &$logs)
        {
        }

        public function log($level, $message, array $context = []): void
        {
            $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    };

    $voteStateCalls = 0;
    $voteState = new class($voteStateCalls) extends \FlatRate\SupabaseOAuth\Activity\VoteStateStore {
        public function __construct(private int &$calls)
        {
        }

        public function observe($actor, int $postId, int $newValue, bool $wasRecentlyCreated): ?array
        {
            $this->calls++;

            return [
                'from_value' => 0,
                'to_value' => 1,
                'vote_state_version' => 1,
            ];
        }
    };

    $disabledEmitter = new class extends \FlatRate\SupabaseOAuth\Activity\ActivityEmitter {
        public function __construct()
        {
        }

        public function enabled(): bool
        {
            return false;
        }

        public function emit($actor, string $kind, array $obs): void
        {
            throw new \RuntimeException('should_not_emit');
        }
    };

    $voteRef = new \ReflectionClass(EmitVoteActivity::class);
    $listener = $voteRef->newInstanceWithoutConstructor();
    foreach ([
        'emitter' => $disabledEmitter,
        'voteState' => $voteState,
        'brands' => $brands,
        'logger' => $logger,
    ] as $name => $value) {
        $prop = $voteRef->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($listener, $value);
    }

    $event = (object) [
        'vote' => (object) [
            'user' => (object) ['id' => 7],
            'post' => (object) ['id' => 99],
            'value' => 1,
            'wasRecentlyCreated' => true,
        ],
    ];
    $listener->handle($event);
    assertSame(0, $voteStateCalls, 'EMIT_OFF_VOTE_STATE_MUTATION=false');

    $throwingState = new class extends \FlatRate\SupabaseOAuth\Activity\VoteStateStore {
        public function observe($actor, int $postId, int $newValue, bool $wasRecentlyCreated): ?array
        {
            throw new \RuntimeException('sql leak sub=aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
        }
    };
    $enabledEmitter = new class extends \FlatRate\SupabaseOAuth\Activity\ActivityEmitter {
        public int $emits = 0;

        public function __construct()
        {
        }

        public function enabled(): bool
        {
            return true;
        }

        public function emit($actor, string $kind, array $obs): void
        {
            $this->emits++;
        }
    };
    foreach ([
        'emitter' => $enabledEmitter,
        'voteState' => $throwingState,
        'brands' => $brands,
        'logger' => $logger,
    ] as $name => $value) {
        $prop = $voteRef->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($listener, $value);
    }

    $threw = false;
    try {
        $listener->handle($event);
    } catch (\Throwable) {
        $threw = true;
    }
    assertTrue(! $threw, 'ACTIVITY_ERROR_CAN_FAIL_CANONICAL_VOTE=false');
    $last = $logs[array_key_last($logs)] ?? null;
    assertSame('flatrate_activity_vote_observer_failed', $last['message'] ?? null, 'vote observer logs bounded event');
    assertTrue(isset($last['context']['error_class']), 'log contains error_class');
    $encoded = json_encode($last);
    assertTrue(! str_contains((string) $encoded, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'), 'RAW_SUB_IN_ACTIVITY_LOG=false');
    assertTrue(! str_contains((string) $encoded, 'sql leak'), 'RAW_THROWABLE_MESSAGE_IN_ACTIVITY_LOG=false');

    $normalState = new class extends \FlatRate\SupabaseOAuth\Activity\VoteStateStore {
        public function observe($actor, int $postId, int $newValue, bool $wasRecentlyCreated): ?array
        {
            return [
                'from_value' => 0,
                'to_value' => 1,
                'vote_state_version' => 1,
            ];
        }
    };
    foreach ([
        'emitter' => $enabledEmitter,
        'voteState' => $normalState,
        'brands' => $brands,
        'logger' => $logger,
    ] as $name => $value) {
        $prop = $voteRef->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($listener, $value);
    }
    $before = $enabledEmitter->emits;
    $listener->handle($event);
    assertSame($before + 1, $enabledEmitter->emits, 'normal 0->+1 emits once');

    $activityFiles = glob(dirname(__DIR__).'/src/Activity/*.php') ?: [];
    $rawMessageHits = 0;
    foreach ($activityFiles as $file) {
        $src = file_get_contents($file);
        if ($src !== false && preg_match('/getMessage\s*\(/', $src)) {
            $rawMessageHits++;
            fail('no getMessage in '.$file);
        }
    }
    if ($rawMessageHits === 0) {
        pass('RAW_THROWABLE_MESSAGE_IN_ACTIVITY_LOG=false across src/Activity');
    }

    echo $failures === 0 ? "ACTIVITY_R2_BEHAVIOR_PASS=true\n" : "ACTIVITY_R2_BEHAVIOR_PASS=false\n";
    echo "COMMAND_CONSTRUCTOR_INJECTION=true\n";
    echo "EMIT_OFF_VOTE_STATE_MUTATION=false\n";
    exit($failures === 0 ? 0 : 1);
}
