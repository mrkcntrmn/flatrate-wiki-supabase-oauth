<?php

/**
 * External Activity outbox drain endpoint gates (provider-independent executor).
 *
 * Fixture token only — never a production scheduler secret.
 */

declare(strict_types=1);

namespace {
    $harnessDir = __DIR__.'/harness/activity-r1';
    $autoload = $harnessDir.'/vendor/autoload.php';
    if (! is_file($autoload)) {
        fwrite(STDERR, "ACTIVITY_R1_HARNESS_MISSING_VENDOR: run composer install in {$harnessDir}\n");
        exit(2);
    }
    require $autoload;

    use FlatRate\SupabaseOAuth\Activity\ActivityDrainAuthenticator;
    use FlatRate\SupabaseOAuth\Activity\ActivityOutboxDrainer;
    use FlatRate\SupabaseOAuth\Activity\DrainActivityOutboxController;
    use Laminas\Diactoros\ServerRequest;
    use Laminas\Diactoros\Stream;
    use Psr\Log\AbstractLogger;

    if (! interface_exists(\Flarum\Settings\SettingsRepositoryInterface::class, false)) {
        eval('namespace Flarum\\Settings { interface SettingsRepositoryInterface {
            public function all();
            public function get($key, $default = null);
            public function set($key, $value);
            public function delete($key);
        }}');
    }

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

    final class FakeSettings implements \Flarum\Settings\SettingsRepositoryInterface
    {
        /** @param array<string, mixed> $values */
        public function __construct(private array $values = [])
        {
        }

        public function all(): array
        {
            return $this->values;
        }

        public function get($key, $default = null)
        {
            return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
        }

        public function set($key, $value): void
        {
            $this->values[$key] = $value;
        }

        public function delete($key): void
        {
            unset($this->values[$key]);
        }
    }

    final class RecordingLogger extends AbstractLogger
    {
        /** @var list<array{level:string,message:string,context:array}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    }

    final class FakeDrainer extends ActivityOutboxDrainer
    {
        public int $calls = 0;
        /** @var list<array<int, mixed>> */
        public array $argSets = [];
        public int $returnProcessed = 0;
        public ?\Throwable $throw = null;

        public function __construct()
        {
        }

        public function drain(int $limit = self::DEFAULT_BATCH_SIZE): int
        {
            $this->calls++;
            $this->argSets[] = func_get_args();
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->returnProcessed;
        }
    }

    $fixtureToken = 'test_fixture_only_do_not_use_in_production_drain_token_r1_abcdef';
    $fixtureDigest = hash('sha256', $fixtureToken);
    assertSame(64, strlen($fixtureDigest), 'FIXTURE_DIGEST_LEN');

    putenv(ActivityDrainAuthenticator::ENV_KEY);
    unset($_ENV[ActivityDrainAuthenticator::ENV_KEY], $_SERVER[ActivityDrainAuthenticator::ENV_KEY]);

    $reqNoAuth = new ServerRequest([], [], 'https://forum.example/api/flatrate-activity/drain', 'POST');

    $authEmpty = new ActivityDrainAuthenticator(new FakeSettings([]));
    assertTrue(! $authEmpty->configured(), 'AUTH_NOT_CONFIGURED_WHEN_EMPTY');
    assertTrue(! $authEmpty->authenticate($reqNoAuth), 'AUTH_FAILS_WHEN_NOT_CONFIGURED');

    $authSetting = new ActivityDrainAuthenticator(new FakeSettings([
        ActivityDrainAuthenticator::SETTING_KEY => $fixtureDigest,
    ]));
    assertTrue($authSetting->configured(), 'AUTH_CONFIGURED_FROM_SETTING');
    assertTrue(! $authSetting->authenticate($reqNoAuth), 'AUTH_MISSING_AUTHORIZATION');
    assertTrue(! $authSetting->authenticate($reqNoAuth->withHeader('Authorization', 'Basic dXNlcjpwYXNz')), 'AUTH_REJECTS_BASIC');
    assertTrue(! $authSetting->authenticate($reqNoAuth->withHeader('Authorization', 'Bearer ')), 'AUTH_REJECTS_EMPTY_BEARER');
    assertTrue(! $authSetting->authenticate($reqNoAuth->withHeader('Authorization', 'Bearer short')), 'AUTH_REJECTS_MALFORMED_TOKEN');
    $wrong = $reqNoAuth->withHeader('Authorization', 'Bearer '.str_repeat('x', 40));
    assertTrue(! $authSetting->authenticate($wrong), 'AUTH_REJECTS_WRONG_TOKEN');
    $valid = $reqNoAuth->withHeader('Authorization', 'Bearer '.$fixtureToken);
    assertTrue($authSetting->authenticate($valid), 'AUTH_ACCEPTS_VALID_TOKEN');

    $authUpperDigest = new ActivityDrainAuthenticator(new FakeSettings([
        ActivityDrainAuthenticator::SETTING_KEY => strtoupper($fixtureDigest),
    ]));
    assertTrue($authUpperDigest->authenticate($valid), 'AUTH_NORMALIZES_DIGEST_CASE');

    putenv(ActivityDrainAuthenticator::ENV_KEY.'=not-a-digest');
    $_ENV[ActivityDrainAuthenticator::ENV_KEY] = 'not-a-digest';
    $authMalformedEnv = new ActivityDrainAuthenticator(new FakeSettings([
        ActivityDrainAuthenticator::SETTING_KEY => $fixtureDigest,
    ]));
    assertTrue($authMalformedEnv->configured(), 'AUTH_MALFORMED_ENV_FALLS_BACK_TO_SETTING');
    assertTrue($authMalformedEnv->authenticate($valid), 'AUTH_MALFORMED_ENV_STILL_AUTHENTICATES_VIA_SETTING');

    $altToken = 'test_fixture_only_do_not_use_in_production_drain_token_r1_env_wins_xx';
    $altDigest = hash('sha256', $altToken);
    putenv(ActivityDrainAuthenticator::ENV_KEY.'='.$altDigest);
    $_ENV[ActivityDrainAuthenticator::ENV_KEY] = $altDigest;
    $authEnvWins = new ActivityDrainAuthenticator(new FakeSettings([
        ActivityDrainAuthenticator::SETTING_KEY => $fixtureDigest,
    ]));
    assertTrue($authEnvWins->authenticate(
        $reqNoAuth->withHeader('Authorization', 'Bearer '.$altToken)
    ), 'AUTH_ENV_DIGEST_WINS');
    assertTrue(! $authEnvWins->authenticate($valid), 'AUTH_ENV_DIGEST_OVERRIDES_SETTING');

    putenv(ActivityDrainAuthenticator::ENV_KEY);
    unset($_ENV[ActivityDrainAuthenticator::ENV_KEY], $_SERVER[ActivityDrainAuthenticator::ENV_KEY]);

    $authSrc = (string) file_get_contents(dirname(__DIR__).'/src/Activity/ActivityDrainAuthenticator.php');
    assertTrue(str_contains($authSrc, 'hash_equals'), 'AUTH_USES_HASH_EQUALS');
    assertTrue(! preg_match("/flatrate-activity\\.drain_token['\"]/", $authSrc), 'AUTH_NO_RAW_TOKEN_SETTING_KEY');

    $logger = new RecordingLogger();
    $drainer = new FakeDrainer();
    $drainer->returnProcessed = 0;

    $controllerUnconfigured = new DrainActivityOutboxController(
        new ActivityDrainAuthenticator(new FakeSettings([])),
        $drainer,
        $logger
    );
    $r503 = $controllerUnconfigured->handle($reqNoAuth);
    assertSame(503, $r503->getStatusCode(), 'ENDPOINT_NOT_CONFIGURED=503');
    $b503 = json_decode((string) $r503->getBody(), true);
    assertSame('scheduler_not_configured', $b503['error'] ?? null, 'ENDPOINT_NOT_CONFIGURED_ERROR');
    assertSame(0, $drainer->calls, 'ENDPOINT_NOT_CONFIGURED_NO_DRAIN');

    $controller = new DrainActivityOutboxController(
        new ActivityDrainAuthenticator(new FakeSettings([
            ActivityDrainAuthenticator::SETTING_KEY => $fixtureDigest,
        ])),
        $drainer,
        $logger
    );

    assertSame(401, $controller->handle($reqNoAuth)->getStatusCode(), 'ENDPOINT_MISSING_AUTH=401');
    assertSame('unauthorized', json_decode((string) $controller->handle($reqNoAuth)->getBody(), true)['error'] ?? null, 'ENDPOINT_MISSING_AUTH_ERROR');
    assertSame(401, $controller->handle($wrong)->getStatusCode(), 'ENDPOINT_BAD_AUTH=401');
    assertSame(401, $controller->handle($reqNoAuth->withHeader('Authorization', 'Bearer short'))->getStatusCode(), 'ENDPOINT_MALFORMED_AUTH=401');

    $r200 = $controller->handle($valid);
    assertSame(200, $r200->getStatusCode(), 'ENDPOINT_VALID_AUTH_EMPTY_OUTBOX=200');
    $b200 = json_decode((string) $r200->getBody(), true);
    assertSame(true, $b200['ok'] ?? null, 'ENDPOINT_VALID_AUTH_OK');
    assertSame(0, $b200['processed'] ?? null, 'ENDPOINT_VALID_AUTH_PROCESSED_ZERO');
    assertSame(1, $drainer->calls, 'ENDPOINT_VALID_AUTH_DRAINS');
    assertSame([], $drainer->argSets[0] ?? ['sentinel'], 'CALLER_CANNOT_SET_LIMIT');

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, '{"limit":99}');
    rewind($stream);
    $withBody = $valid->withBody(new Stream($stream));
    $callsBeforeBody = $drainer->calls;
    $r400 = $controller->handle($withBody);
    assertSame(400, $r400->getStatusCode(), 'ENDPOINT_BODY_REJECTED=400');
    assertSame('invalid_request', json_decode((string) $r400->getBody(), true)['error'] ?? null, 'ENDPOINT_BODY_REJECTED_ERROR');
    assertSame($callsBeforeBody, $drainer->calls, 'ENDPOINT_BODY_REJECTED_NO_DRAIN');

    $encoded = (string) json_encode($b200);
    assertTrue(! str_contains($encoded, 'asub_'), 'RESPONSE_CONTAINS_NO_ACTIVITY_SUBJECT');
    assertTrue(! str_contains($encoded, 'payload'), 'RESPONSE_CONTAINS_NO_PAYLOAD');
    assertTrue(! str_contains($encoded, $fixtureToken), 'RESPONSE_CONTAINS_NO_SECRET');
    assertTrue(! str_contains($encoded, 'Bearer'), 'RESPONSE_CONTAINS_NO_BEARER');
    assertSame('no-store', $r200->getHeaderLine('Cache-Control'), 'RESPONSE_CACHE_CONTROL');
    assertSame('no-referrer', $r200->getHeaderLine('Referrer-Policy'), 'RESPONSE_REFERRER_POLICY');

    $drainer->throw = new \RuntimeException('secret_stack_detail_must_not_leak');
    $logger->records = [];
    $rFail = $controller->handle($valid);
    assertSame(503, $rFail->getStatusCode(), 'EXCEPTION_PUBLIC_STATUS=503');
    assertSame('drain_failed', json_decode((string) $rFail->getBody(), true)['error'] ?? null, 'EXCEPTION_PUBLIC_RESPONSE_SAFE');
    assertTrue(! str_contains((string) $rFail->getBody(), 'secret_stack'), 'EXCEPTION_NO_MESSAGE_LEAK');
    assertSame('flatrate_activity_external_drain_failed', $logger->records[0]['message'] ?? null, 'EXCEPTION_LOG_EVENT');
    assertSame(\RuntimeException::class, $logger->records[0]['context']['error_class'] ?? null, 'EXCEPTION_LOG_CLASS');
    $logJson = (string) json_encode($logger->records);
    assertTrue(! str_contains($logJson, $fixtureToken), 'EXCEPTION_LOG_SAFE_NO_TOKEN');
    assertTrue(! str_contains($logJson, 'secret_stack'), 'EXCEPTION_LOG_SAFE_NO_MESSAGE');

    $drainer->throw = null;
    $drainer->returnProcessed = 3;
    assertSame(3, json_decode((string) $controller->handle($valid)->getBody(), true)['processed'] ?? null, 'ENDPOINT_VALID_AUTH_PROCESSED_NONZERO');

    $middlewareSrc = (string) file_get_contents(dirname(__DIR__).'/src/Middleware/RequireFlatRateIdentity.php');
    assertTrue(str_contains($middlewareSrc, '/api/token') && str_contains($middlewareSrc, '/api/users'), 'MIDDLEWARE_RESTRICTS_ONLY_LOGIN_USER_PATHS');
    assertTrue(! str_contains($middlewareSrc, 'flatrate-activity/drain'), 'MIDDLEWARE_DOES_NOT_SPECIAL_CASE_DRAIN');

    $extend = (string) file_get_contents(dirname(__DIR__).'/extend.php');
    assertTrue(
        str_contains($extend, '/flatrate-activity/drain')
        && str_contains($extend, 'flatrate.activity.drain')
        && str_contains($extend, 'DrainActivityOutboxController::class'),
        'DRAIN_ROUTE_REGISTERED'
    );
    assertTrue(str_contains($extend, "default('flatrate-activity.drain_token_sha256', '')"), 'DRAIN_SETTING_DEFAULT_EMPTY');
    assertTrue(
        str_contains($extend, 'DrainActivityOutboxCommand::class')
        && str_contains($extend, 'everyMinute()->withoutOverlapping()'),
        'NATIVE_SCHEDULER_REGISTRATION_PRESERVED'
    );

    $activityPhp = '';
    foreach (glob(dirname(__DIR__).'/src/Activity/*.php') ?: [] as $file) {
        $activityPhp .= file_get_contents($file)."\n";
    }
    assertTrue(! preg_match("/flatrate-activity\\.drain_token['\"]/", $activityPhp), 'RAW_TOKEN_NOT_STORED');

    if ($failures > 0) {
        fwrite(STDERR, "ACTIVITY_EXTERNAL_DRAIN_ENDPOINT_FAILURES={$failures}\n");
        exit(1);
    }

    echo "ACTIVITY_EXTERNAL_DRAIN_ENDPOINT=PASS\n";
    exit(0);
}
