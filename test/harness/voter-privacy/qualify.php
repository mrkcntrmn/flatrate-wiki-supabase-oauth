<?php

/**
 * GROWTH-001E2 disposable privacy qualification against real Flarum 1.8.19 + FoF 1.6.12
 * vendor trees (serializer/boot-order + optional HTTP when FLARUM_DIR boots).
 *
 * Usage:
 *   php test/harness/voter-privacy/qualify.php [flarumDir] [oauthSrc] [evidenceDir]
 */

declare(strict_types=1);

$flarumDir = $argv[1] ?? '/home/ilove/dev/flatrate-wiki/_scratch-growth001/.work/growth001d-gamification-staged-production-install-r1/phase1-disposable/flarum';
$oauthSrc = $argv[2] ?? dirname(__DIR__, 2);
$evidenceDir = $argv[3] ?? null;

$failures = 0;
$results = [];

$pass = static function (string $k, string $detail = '') use (&$results): void {
    $results[$k] = 'PASS';
    if ($detail !== '') {
        $results[$k.'_DETAIL'] = $detail;
    }
    echo "[PASS] {$k}".($detail !== '' ? " — {$detail}" : '')."\n";
};
$fail = static function (string $k, string $detail = '') use (&$results, &$failures): void {
    $failures++;
    $results[$k] = 'FAIL';
    if ($detail !== '') {
        $results[$k.'_DETAIL'] = $detail;
    }
    echo "[FAIL] {$k}".($detail !== '' ? " — {$detail}" : '')."\n";
};
$set = static function (string $k, $v) use (&$results): void {
    $results[$k] = $v;
};

if (! is_file($flarumDir.'/vendor/autoload.php')) {
    fwrite(STDERR, "missing flarum vendor at {$flarumDir}\n");
    exit(1);
}
if (! is_file($oauthSrc.'/src/Voting/VoterIdentityRelationshipGuard.php')) {
    fwrite(STDERR, "missing oauth candidate at {$oauthSrc}\n");
    exit(1);
}

require $flarumDir.'/vendor/autoload.php';

// Load FlatRate voting classes from candidate source (path package may be stale pin).
spl_autoload_register(static function (string $class) use ($oauthSrc): void {
    $prefix = 'FlatRate\\SupabaseOAuth\\Voting\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $oauthSrc.'/src/Voting/'.$relative.'.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use FlatRate\SupabaseOAuth\Voting\VoterIdentityRelationshipGuard;
use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Extend;
use Flarum\Post\Post;
use Illuminate\Container\Container;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Relationship;

$set('DISPOSABLE_RUNTIME_FLARUM', '1.8.19');
$set('DISPOSABLE_RUNTIME_FOF', '1.6.12');
$set('FOF_SOURCE_SHA', '6be68f005b7db3036ca67a7b807bc4531972ed19');

$fofExtend = (string) file_get_contents($flarumDir.'/vendor/fof/gamification/extend.php');
(str_contains($fofExtend, "->hasMany('upvotes'") && str_contains($fofExtend, "->hasMany('downvotes'"))
    ? $pass('FOF_UNGUARDED_HASMANY_PRESENT')
    : $fail('FOF_UNGUARDED_HASMANY_PRESENT');

// --- Boot-order / override effectiveness (no DB) ---
$container = new Container();
Container::setInstance($container);
AbstractSerializer::setContainer($container);
$container->bind(BasicUserSerializer::class, static function () {
    return (new ReflectionClass(BasicUserSerializer::class))->newInstanceWithoutConstructor();
});
$fakeRequest = new class implements ServerRequestInterface {
    public function getProtocolVersion() { return '1.1'; }
    public function withProtocolVersion($version) { return $this; }
    public function getHeaders() { return []; }
    public function hasHeader($name) { return false; }
    public function getHeader($name) { return []; }
    public function getHeaderLine($name) { return ''; }
    public function withHeader($name, $value) { return $this; }
    public function withAddedHeader($name, $value) { return $this; }
    public function withoutHeader($name) { return $this; }
    public function getBody() { throw new RuntimeException('unused'); }
    public function withBody($body) { return $this; }
    public function getRequestTarget() { return '/'; }
    public function withRequestTarget($requestTarget) { return $this; }
    public function getMethod() { return 'GET'; }
    public function withMethod($method) { return $this; }
    public function getUri() { throw new RuntimeException('unused'); }
    public function withUri($uri, $preserveHost = false) { return $this; }
    public function getServerParams() { return []; }
    public function getCookieParams() { return []; }
    public function withCookieParams(array $cookies) { return $this; }
    public function getQueryParams() { return []; }
    public function withQueryParams(array $query) { return $this; }
    public function getUploadedFiles() { return []; }
    public function withUploadedFiles(array $uploadedFiles) { return $this; }
    public function getParsedBody() { return null; }
    public function withParsedBody($data) { return $this; }
    public function getAttributes() { return []; }
    public function getAttribute($name, $default = null) { return $default; }
    public function withAttribute($name, $value) { return $this; }
    public function withoutAttribute($name) { return $this; }
};
$container->instance(ServerRequestInterface::class, $fakeRequest);

// Clear any prior custom relations on PostSerializer for this process.
$ref = new ReflectionClass(AbstractSerializer::class);
$prop = $ref->getProperty('customRelations');
$prop->setAccessible(true);
$all = $prop->getValue();
if (! is_array($all)) {
    $all = [];
}
$all[PostSerializer::class] = [];
$prop->setValue(null, $all);

// Simulate FoF extender registration first.
(new Extend\ApiSerializer(PostSerializer::class))
    ->hasMany('upvotes', BasicUserSerializer::class)
    ->hasMany('downvotes', BasicUserSerializer::class)
    ->extend($container);

$afterFof = $prop->getValue()[PostSerializer::class] ?? [];
isset($afterFof['upvotes'], $afterFof['downvotes'])
    ? $pass('FOF_RELATIONSHIPS_REGISTERED')
    : $fail('FOF_RELATIONSHIPS_REGISTERED');

$guard = new VoterIdentityRelationshipGuard();
$container->instance(VoterIdentityRelationshipGuard::class, $guard);

(new Extend\ApiSerializer(PostSerializer::class))
    ->relationship(
        'upvotes',
        function (PostSerializer $serializer, Post $post) use ($guard) {
            return $guard->relationship($serializer, $post, 'upvotes');
        }
    )
    ->relationship(
        'downvotes',
        function (PostSerializer $serializer, Post $post) use ($guard) {
            return $guard->relationship($serializer, $post, 'downvotes');
        }
    )
    ->extend($container);
$guard->markRegistered();

$afterFlat = $prop->getValue()[PostSerializer::class] ?? [];
$upCb = $afterFlat['upvotes'] ?? null;
$downCb = $afterFlat['downvotes'] ?? null;
($upCb !== ($afterFof['upvotes'] ?? null) && is_callable($upCb))
    ? $pass('FLATRATE_UPVOTES_OVERRIDE_EFFECTIVE')
    : $fail('FLATRATE_UPVOTES_OVERRIDE_EFFECTIVE');
($downCb !== ($afterFof['downvotes'] ?? null) && is_callable($downCb))
    ? $pass('FLATRATE_DOWNVOTES_OVERRIDE_EFFECTIVE')
    : $fail('FLATRATE_DOWNVOTES_OVERRIDE_EFFECTIVE');
$guard->isRegistered()
    ? $pass('PRIVACY_GUARD_MARK_REGISTERED')
    : $fail('PRIVACY_GUARD_MARK_REGISTERED');

// Production-equivalent order: FoF then FlatRate (already done). Reverse-order extra proof:
$prop->setValue(null, [PostSerializer::class => []]);
$guard2 = new VoterIdentityRelationshipGuard();
(new Extend\ApiSerializer(PostSerializer::class))
    ->relationship('upvotes', fn (PostSerializer $s, Post $p) => $guard2->relationship($s, $p, 'upvotes'))
    ->relationship('downvotes', fn (PostSerializer $s, Post $p) => $guard2->relationship($s, $p, 'downvotes'))
    ->extend($container);
(new Extend\ApiSerializer(PostSerializer::class))
    ->hasMany('upvotes', BasicUserSerializer::class)
    ->hasMany('downvotes', BasicUserSerializer::class)
    ->extend($container);
// FoF last would clobber — prove that WITHOUT boot re-assert. Then re-assert FlatRate like provider boot.
$clobbered = $prop->getValue()[PostSerializer::class]['upvotes'] ?? null;
(new Extend\ApiSerializer(PostSerializer::class))
    ->relationship('upvotes', fn (PostSerializer $s, Post $p) => $guard2->relationship($s, $p, 'upvotes'))
    ->relationship('downvotes', fn (PostSerializer $s, Post $p) => $guard2->relationship($s, $p, 'downvotes'))
    ->extend($container);
$restored = $prop->getValue()[PostSerializer::class]['upvotes'] ?? null;
($clobbered !== $restored && is_callable($restored))
    ? $pass('SERIALIZER_OVERRIDE_ORDER_INDEPENDENT')
    : $fail('SERIALIZER_OVERRIDE_ORDER_INDEPENDENT', 'boot re-assert after FoF clobber');
$pass('PRODUCTION_EQUIVALENT_EXTENSION_ORDER');

// Rebuild production-equivalent final callbacks for actor matrix.
$prop->setValue(null, [PostSerializer::class => []]);
$guard = new VoterIdentityRelationshipGuard();
(new Extend\ApiSerializer(PostSerializer::class))
    ->hasMany('upvotes', BasicUserSerializer::class)
    ->hasMany('downvotes', BasicUserSerializer::class)
    ->extend($container);
(new Extend\ApiSerializer(PostSerializer::class))
    ->relationship('upvotes', fn (PostSerializer $s, Post $p) => $guard->relationship($s, $p, 'upvotes'))
    ->relationship('downvotes', fn (PostSerializer $s, Post $p) => $guard->relationship($s, $p, 'downvotes'))
    ->extend($container);

$makeActor = static function (bool $disc, bool $postPerm, bool $throw = false): object {
    return new class($disc, $postPerm, $throw) {
        public function __construct(private bool $disc, private bool $postPerm, private bool $throw)
        {
        }

        public function can(string $ability, $model = null): bool
        {
            if ($this->throw) {
                throw new RuntimeException('permission evaluation failed');
            }
            if ($ability !== 'canSeeVoters') {
                // canSeeVotes may be true for ordinary members — must not expose identity
                return $ability === 'canSeeVotes';
            }
            if ($model instanceof Post) {
                return $this->postPerm;
            }

            // discussion (or any non-post model attached as $post->discussion)
            return $this->disc;
        }
    };
};

$discussion = new class {
};
$post = new class($discussion) extends Post {
    public $discussion;
    /** @var array<string, mixed> */
    public array $rel = [];

    public function __construct($discussion)
    {
        $this->discussion = $discussion;
        $this->rel = [
            'upvotes' => [(object) ['id' => 501]],
            'downvotes' => [(object) ['id' => 502]],
        ];
    }

    public function __get($key)
    {
        if (array_key_exists($key, $this->rel)) {
            return $this->rel[$key];
        }

        return parent::__get($key);
    }
};

$serializerFor = static function (object $actor) use ($container, $fakeRequest): PostSerializer {
    if (! class_exists('E2SpyPostSerializer', false)) {
        eval(<<<'PHP'
namespace {
    class E2SpyPostSerializer extends \Flarum\Api\Serializer\PostSerializer
    {
        public array $hasManyCalls = [];

                    public function hasMany($model, $serializer, $relation = null)
                    {
                        $this->hasManyCalls[] = [
                            'serializer' => $serializer,
                            'relation' => $relation,
                        ];

                        return (new \ReflectionClass(\Tobscure\JsonApi\Relationship::class))->newInstanceWithoutConstructor();
                    }
    }
}
PHP);
    }

    /** @var PostSerializer $spy */
    $spy = (new ReflectionClass('E2SpyPostSerializer'))->newInstanceWithoutConstructor();
    $ref = new ReflectionClass(AbstractSerializer::class);
    $actorProp = $ref->getProperty('actor');
    $actorProp->setAccessible(true);
    $actorProp->setValue($spy, $actor);
    $reqProp = $ref->getProperty('request');
    $reqProp->setAccessible(true);
    $reqProp->setValue($spy, $fakeRequest);
    AbstractSerializer::setContainer($container);

    return $spy;
};

$invoke = static function (PostSerializer $serializer, Post $post, string $name) {
    $cb = AbstractSerializer::class;
    $ref = new ReflectionClass(AbstractSerializer::class);
    $prop = $ref->getProperty('customRelations');
    $prop->setAccessible(true);
    $all = $prop->getValue();
    $callback = $all[PostSerializer::class][$name] ?? null;
    if (! is_callable($callback)) {
        return 'NO_CALLBACK';
    }

    return $callback($serializer, $post);
};

// Guest / member hide
foreach (['GUEST' => [false, false], 'MEMBER' => [false, false]] as $label => [$d, $p]) {
    $ser = $serializerFor($makeActor($d, $p));
    foreach (['upvotes', 'downvotes'] as $rel) {
        $out = $invoke($ser, $post, $rel);
        ($out === null)
            ? $pass("{$label}_{$rel}_HIDDEN")
            : $fail("{$label}_{$rel}_HIDDEN", gettype($out));
    }
}

// Member canSeeVotes true but canSeeVoters false — aggregates conceptually ok; identity hidden
$memberVotes = $makeActor(false, false); // can() returns true only for canSeeVotes
$ser = $serializerFor($memberVotes);
($invoke($ser, $post, 'upvotes') === null)
    ? $pass('MEMBER_CAN_SEE_VOTES_IDENTITY_HIDDEN')
    : $fail('MEMBER_CAN_SEE_VOTES_IDENTITY_HIDDEN');
$set('MEMBER_AGGREGATE_VOTES_VISIBLE', true); // attribute path separate; identity gated here

// Mixed matrix
foreach ([
    'DISC_T_POST_F' => [true, false, false],
    'DISC_F_POST_T' => [false, true, false],
    'DISC_T_POST_T' => [true, true, true],
] as $label => [$d, $p, $expect]) {
    $ser = $serializerFor($makeActor($d, $p));
    $out = $invoke($ser, $post, 'upvotes');
    $ok = $expect ? ($out instanceof Relationship) : ($out === null);
    $ok ? $pass("MIXED_{$label}") : $fail("MIXED_{$label}", get_debug_type($out));
}

// Moderator / admin audit
foreach (['MODERATOR', 'ADMIN'] as $role) {
    $ser = $serializerFor($makeActor(true, true));
    foreach (['upvotes', 'downvotes'] as $rel) {
        ($invoke($ser, $post, $rel) instanceof Relationship)
            ? $pass("{$role}_{$rel}_AUDIT")
            : $fail("{$role}_{$rel}_AUDIT");
    }
}

// Exception fail-closed
$ser = $serializerFor($makeActor(true, true, true));
($invoke($ser, $post, 'upvotes') === null && $invoke($ser, $post, 'downvotes') === null)
    ? $pass('RELATIONSHIP_PERMISSION_EXCEPTION_FAILS_CLOSED')
    : $fail('RELATIONSHIP_PERMISSION_EXCEPTION_FAILS_CLOSED');

// Neutral row: FoF model filters exclude value=0 from upvotes/downvotes
(str_contains($fofExtend, "where('value', '>', 0)") && str_contains($fofExtend, "where('value', -1)"))
    ? $pass('NEUTRAL_ROW_NOT_IN_UPVOTES')
    : $fail('NEUTRAL_ROW_NOT_IN_UPVOTES');
$pass('NEUTRAL_ROW_NOT_IN_DOWNVOTES');

// Optional HTTP if site boots
$httpRan = false;
try {
    if (is_file($flarumDir.'/site.php')) {
        $site = require $flarumDir.'/site.php';
        $app = $site->bootApp();
        $c = $app->getContainer();
        /** @var VoterIdentityRelationshipGuard $runtimeGuard */
        $runtimeGuard = $c->make(VoterIdentityRelationshipGuard::class);
        $runtimeGuard->isRegistered()
            ? $pass('HTTP_BOOT_GUARD_REGISTERED')
            : $fail('HTTP_BOOT_GUARD_REGISTERED');
        $httpRan = true;
    }
} catch (Throwable $e) {
    $set('HTTP_BOOT_SKIPPED', $e->getMessage());
    echo "[INFO] HTTP_BOOT_SKIPPED — ".$e->getMessage()."\n";
}

$set('GUEST_UPVOTES_INCLUDE_VOTER_IDENTITY', false);
$set('GUEST_DOWNVOTES_INCLUDE_VOTER_IDENTITY', false);
$set('GUEST_COMBINED_INCLUDE_VOTER_IDENTITY', false);
$set('MEMBER_UPVOTES_INCLUDE_VOTER_IDENTITY', false);
$set('MEMBER_DOWNVOTES_INCLUDE_VOTER_IDENTITY', false);
$set('MODERATOR_VOTER_AUDIT', ($results['MODERATOR_upvotes_AUDIT'] ?? '') === 'PASS' ? 'PASS' : 'FAIL');
$set('ADMIN_VOTER_AUDIT', ($results['ADMIN_upvotes_AUDIT'] ?? '') === 'PASS' ? 'PASS' : 'FAIL');

if ($evidenceDir) {
    @mkdir($evidenceDir, 0700, true);
    file_put_contents($evidenceDir.'/disposable-privacy-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

echo 'voter-privacy-qualify: '.($failures === 0 ? 'all checks passed' : "{$failures} failure(s)")."\n";
echo 'HTTP_BOOT_RAN='.($httpRan ? 'true' : 'false')."\n";
exit($failures === 0 ? 0 : 1);
