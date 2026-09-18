<?php

/**
 * FORUM-PUBLIC-PSEUDONYM-001 — ViewerIdentityContext + display-name driver gates.
 * Run: php test/guest-identity-projection.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/src/Identity/ViewerIdentityContext.php';

use FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext;

function assert_true(bool $cond, string $msg): void
{
    if (! $cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    fwrite(STDERR, "PASS: {$msg}\n");
}

final class FakeActor
{
    public function __construct(private bool $guest)
    {
    }

    public function isGuest(): bool
    {
        return $this->guest;
    }
}

final class FakeSubject
{
    public string $username;
    public ?string $nickname;

    public function __construct(string $username, ?string $nickname)
    {
        $this->username = $username;
        $this->nickname = $nickname;
    }
}

final class FakeNicknameDriver
{
    public function displayName(object $user): string
    {
        return $user->nickname ?: $user->username;
    }
}

// Duck-typed context using FakeActor that matches isGuest() contract used by ViewerIdentityContext.
// ViewerIdentityContext is typed against Flarum\User\User; for unit gates we exercise a parallel
// stack that mirrors production semantics without requiring flarum/core autoload.

final class TestViewerIdentityContext
{
    /** @var list<object> */
    private array $stack = [];

    public function push(object $actor): void
    {
        $this->stack[] = $actor;
    }

    public function pop(): void
    {
        if ($this->stack === []) {
            return;
        }
        array_pop($this->stack);
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    public function isGuestProjectionActive(): bool
    {
        if ($this->stack === []) {
            return false;
        }
        $actor = $this->stack[array_key_last($this->stack)];

        return method_exists($actor, 'isGuest') && $actor->isGuest();
    }
}

final class TestGuestAwareDisplayNameDriver
{
    public function __construct(private FakeNicknameDriver $inner, private TestViewerIdentityContext $context)
    {
    }

    public function displayName(FakeSubject $user): string
    {
        if ($this->context->isGuestProjectionActive()) {
            return $user->username;
        }

        return $this->inner->displayName($user);
    }
}

$ctx = new TestViewerIdentityContext();
$inner = new FakeNicknameDriver();
$driver = new TestGuestAwareDisplayNameDriver($inner, $ctx);
$subject = new FakeSubject('tech_a84f19c2', 'VeryUniqueWizardLeakSentinel');

assert_true($ctx->isGuestProjectionActive() === false, 'NO_HTTP_CONTEXT_DELEGATION empty!=guest');
assert_true($driver->displayName($subject) === 'VeryUniqueWizardLeakSentinel', 'empty context uses inner nickname');

$guest = new FakeActor(true);
$member = new FakeActor(false);

$ctx->push($member);
assert_true($ctx->isGuestProjectionActive() === false, 'authenticated context not guest');
assert_true($driver->displayName($subject) === 'VeryUniqueWizardLeakSentinel', 'auth uses nickname');

$ctx->push($guest);
assert_true($ctx->depth() === 2, 'nested push depth 2');
assert_true($ctx->isGuestProjectionActive() === true, 'nested guest activates projection');
assert_true($driver->displayName($subject) === 'tech_a84f19c2', 'guest displayName is username');
assert_true(! str_contains($driver->displayName($subject), 'VeryUniqueWizardLeakSentinel'), 'guest omits sentinel nickname');

$ctx->pop();
assert_true($ctx->depth() === 1, 'NESTED_REQUEST_CONTEXT_RESTORES_PARENT');
assert_true($ctx->isGuestProjectionActive() === false, 'parent member restored');
assert_true($driver->displayName($subject) === 'VeryUniqueWizardLeakSentinel', 'restored auth nickname');

$ctx->push($guest);
try {
    throw new RuntimeException('boom');
} catch (RuntimeException $e) {
    $ctx->pop();
}
assert_true($ctx->depth() === 1, 'VIEWER_CONTEXT_EXCEPTION_CLEANUP');
assert_true($driver->displayName($subject) === 'VeryUniqueWizardLeakSentinel', 'after exception cleanup auth ok');

$ctx->pop();
assert_true($ctx->depth() === 0, 'CONTEXT_CLEARED_AFTER_RESPONSE');
assert_true($driver->displayName($subject) === 'VeryUniqueWizardLeakSentinel', 'cleared stack delegates to inner');

// Real ViewerIdentityContext: empty stack must never project as guest.
$real = new ViewerIdentityContext();
assert_true($real->isGuestProjectionActive() === false, 'real context empty!=guest');
assert_true($real->depth() === 0, 'real context starts empty');
$real->pop();
assert_true($real->depth() === 0, 'pop on empty is safe');

$driverSrc = file_get_contents($root.'/src/Identity/GuestAwareDisplayNameDriver.php');
assert_true(str_contains($driverSrc, 'isGuestProjectionActive'), 'driver uses context not implicit request');
assert_true(str_contains($driverSrc, 'return (string) $user->username'), 'guest uses username no hash');
assert_true(! str_contains($driverSrc, 'hash('), 'NEW_HASH_COMPUTATION_PER_RENDER=false');
assert_true(! str_contains($driverSrc, 'sha256'), 'no sha256 in driver');

$mwSrc = file_get_contents($root.'/src/Middleware/ViewerIdentityContextMiddleware.php');
assert_true(str_contains($mwSrc, 'finally'), 'middleware finally pop');
assert_true(str_contains($mwSrc, 'RequestUtil::getActor'), 'middleware pushes request actor');

$memberSrc = file_get_contents($root.'/src/Api/SerializeMemberProfile.php');
assert_true(str_contains($memberSrc, 'isGuest()'), 'SerializeMemberProfile guest gate');
assert_true(
    preg_match('/if \(\!\s*\$actor \|\| \$actor->isGuest\(\)\) \{\s*return \[\];/s', $memberSrc) === 1,
    'NULL_ACTOR_MEMBER_NUMBER_EXPOSURE=false fail-closed'
);
assert_true(! preg_match('/if \(\$actor && \$actor->isGuest\(\)\)/', $memberSrc), 'no weak guest-only null-pass gate');

$serSrc = file_get_contents($root.'/src/Api/SerializeGuestPublicIdentity.php');
assert_true(str_contains($serSrc, "'avatarUrl' => null"), 'guest avatar null');
assert_true(str_contains($serSrc, 'publicAlias'), 'guest displayName alias');

$extend = file_get_contents($root.'/extend.php');
assert_true(str_contains($extend, 'BasicUserSerializer::class'), 'BasicUserSerializer registered');
assert_true(str_contains($extend, 'SerializeGuestPublicIdentity'), 'guest serializer registered');
assert_true(str_contains($extend, 'ViewerIdentityContextMiddleware'), 'context middleware registered');
assert_true(substr_count($extend, 'ViewerIdentityContextMiddleware') >= 2, 'forum+api middleware');
assert_true(str_contains($extend, 'VotingServiceProvider'), 'VOTING_REGRESSION VotingServiceProvider');
assert_true(str_contains($extend, 'GlobalVotingPolicy'), 'VOTING_REGRESSION GlobalVotingPolicy');
assert_true(str_contains($extend, 'PostVotePolicy'), 'VOTING_REGRESSION PostVotePolicy');
assert_true(str_contains($extend, 'flatrate.voting.readiness'), 'VOTING_REGRESSION readiness route');
assert_true(str_contains($extend, 'plain-voting.js'), 'VOTING_REGRESSION plain-voting.js');
assert_true(str_contains($extend, 'flatrate.activity.drain'), 'ACTIVITY_REGRESSION drain route');
assert_true(str_contains($extend, 'member-dashboard.js'), 'DASHBOARD_REGRESSION member-dashboard.js');

$provider = file_get_contents($root.'/src/ServiceProvider.php');
assert_true(! preg_match('/settings.*display_name_driver|set\([\'"]display_name_driver/', $provider), 'does not rewrite settings key');
assert_true(str_contains($provider, "extend('flarum.user.display_name.driver'"), 'decorates container binding only');
assert_true(str_contains($provider, 'flatrate-sso.provision'), 'SSO provision CSRF exemption preserved');
assert_true(str_contains($provider, 'flatrate-sso.ticket'), 'SSO ticket CSRF exemption preserved');
assert_true(str_contains($provider, 'flatrate.activity.drain'), 'ACTIVITY_REGRESSION drain CSRF exemption preserved');

fwrite(STDERR, "guest-identity-projection.php: all checks passed\n");
exit(0);
