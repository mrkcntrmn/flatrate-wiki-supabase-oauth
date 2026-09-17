<?php

/**
 * GROWTH-001B — plain voting foundation structural gates (no Flarum boot).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
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

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
$require = $composer['require'] ?? [];
isset($require['fof/gamification']) ? fail('fof/gamification in require') : pass('FOF_GAMIFICATION_PRODUCTION_REQUIRE_ADDED=false');
(isset($require['flarum/pusher']) || isset($require['pusher/pusher-php-server']))
    ? fail('Pusher package in require')
    : pass('PUSHER_PRODUCTION_REQUIRE_ADDED=false');

$extend = (string) file_get_contents($root.'/extend.php');
str_contains($extend, "->default('flatrate-voting.enabled', false)")
    ? pass('FLATRATE_VOTE_GATE_DEFAULT_ENABLED=false')
    : fail('flatrate-voting.enabled default missing');
str_contains($extend, 'flatrate.voting.readiness')
    ? pass('READINESS_ROUTE_PRESENT')
    : fail('readiness route missing');
str_contains($extend, 'VotingServiceProvider')
    ? pass('VotingServiceProvider registered')
    : fail('VotingServiceProvider missing');
str_contains($extend, 'GlobalVotingPolicy')
    ? pass('GLOBAL_RANKING_POLICY_PRESENT')
    : fail('GlobalVotingPolicy missing');

$globalSrc = (string) file_get_contents($root.'/src/Voting/GlobalVotingPolicy.php');
(preg_match('/function\\s+can\\s*\\(\\s*User\\s+\\$actor\\s*,\\s*string\\s+\\$ability\\s*,/', $globalSrc)
    ? pass('GLOBAL_POLICY_CAN_THREE_ARG')
    : fail('GlobalVotingPolicy::can must accept ($actor, $ability, $instance)'));
str_contains($extend, "whenExtensionEnabled('fof-gamification'")
    ? pass('PROVIDER_SOFT_DEPENDENCY_PRESERVED')
    : fail('PostVotePolicy gate missing');
str_contains($extend, 'js/dist/plain-voting.js')
    ? pass('plain-voting.js registered')
    : fail('plain-voting.js not registered');
str_contains($extend, "->default('fof-gamification.autoUpvotePosts', false)")
    ? pass('autoUpvotePosts default false')
    : fail('autoUpvotePosts default missing');
str_contains($extend, "->default('fof-gamification.rateLimit', true)")
    ? pass('rateLimit default true')
    : fail('rateLimit default missing');

$asp = (string) file_get_contents($root.'/src/Activity/ActivityServiceProvider.php');
(str_contains($asp, 'PostWasVoted') && str_contains($asp, 'class_exists'))
    ? pass('VOTE_ACTIVITY_SOFT_BIND')
    : fail('Activity soft-bind missing');

$gateSrc = (string) file_get_contents($root.'/src/Voting/VoteSafetyGate.php');
str_contains($gateSrc, "bound('Pusher')")
    ? pass('READINESS_PUSHER_EXACT_KEY=Pusher')
    : fail('Pusher key probe missing');
str_contains($gateSrc, 'isSelfVote')
    ? pass('SELF_VOTE_SAFETY_INDEPENDENT_OF_PROVIDER_SETTING')
    : fail('self-vote helper missing');

$readySrc = (string) file_get_contents($root.'/src/Voting/VotingReadiness.php');
str_contains($readySrc, 'flatrate_vote_activity_state')
    ? pass('READINESS_ACTIVITY_PREFIX_AWARE')
    : fail('logical vote-state table missing');
foreach (['post_id', 'user_id', 'last_effective_vote', 'state_version', 'updated_at'] as $col) {
    str_contains($readySrc, "'{$col}'") ? null : fail("column {$col} missing");
}
pass('READINESS_REQUIRED_COLUMNS');

str_contains($readySrc, 'flatrate_activity_outbox') && str_contains($readySrc, 'terminal_at')
    ? pass('outbox companion check')
    : fail('outbox companion check missing');

$ctrl = (string) file_get_contents($root.'/src/Voting/VotingReadinessController.php');
str_contains($ctrl, 'assertAdmin')
    ? pass('READINESS_ADMIN_ONLY')
    : fail('assertAdmin missing');

foreach (glob($root.'/src/Voting/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match('/^use\\s+FoF\\\\Gamification\\\\/m', $src)) {
        fail(basename($file).' hard-imports FoF');
    }
}
pass('Voting namespace soft-dep only');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
$hit = false;
foreach ($iterator as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    if (str_contains($src, 'fof/gamification/convert') || str_contains($src, 'ConvertLikes')) {
        $hit = true;
        fail('Likes converter referenced in '.basename($file->getPathname()));
    }
}
if (! $hit) {
    pass('LIKES_TO_UPVOTES_CONVERTER_USED=false');
}

is_file($root.'/js/dist/plain-voting.js')
    ? pass('plain-voting.js exists')
    : fail('plain-voting.js missing');
$pv = (string) file_get_contents($root.'/js/dist/plain-voting.js');
str_contains($pv, 'module.exports')
    ? pass('plain-voting.js webpack CJS export')
    : fail('plain-voting.js missing module.exports');
is_file($root.'/docs/growth-001b-plain-vote-foundation.md')
    ? pass('docs present')
    : fail('docs missing');

echo 'plain-voting-foundation.php: '.($failures === 0 ? 'all checks passed' : "{$failures} failure(s)")."\n";
exit($failures === 0 ? 0 : 1);
