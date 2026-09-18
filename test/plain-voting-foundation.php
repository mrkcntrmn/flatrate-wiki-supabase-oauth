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
is_file($root.'/src/Voting/VoterIdentityRelationshipGuard.php')
    ? pass('VoterIdentityRelationshipGuard present')
    : fail('VoterIdentityRelationshipGuard missing');
$providerSrc = (string) file_get_contents($root.'/src/Voting/VotingServiceProvider.php');
(str_contains($providerSrc, 'function boot') && str_contains($providerSrc, 'VoterIdentityRelationshipGuard'))
    ? pass('BOOT_TIME_VOTER_IDENTITY_OVERRIDE')
    : fail('VotingServiceProvider boot override missing');
$readySrcEarly = (string) file_get_contents($root.'/src/Voting/VotingReadiness.php');
str_contains($readySrcEarly, 'voter_identity_serializer_guard_unavailable')
    ? pass('PRIVACY_GUARD_READINESS_BLOCKER')
    : fail('voter_identity_serializer_guard_unavailable missing');
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
str_contains($extend, "->default('fof-gamification.upVotesOnly', true)")
    ? pass('upVotesOnly default true')
    : fail('upVotesOnly default must be true');
// FoF setting defaults must be Conditional::whenExtensionDisabled so they do
// not collide with FoF's immutable Settings::default() when the provider boots.
(str_contains($extend, "whenExtensionDisabled('fof-gamification'")
    && str_contains($extend, "->default('fof-gamification.allowSelfVotes', false)"))
    ? pass('FOF_SETTING_DEFAULTS_GATED_WHEN_PROVIDER_DISABLED')
    : fail('FoF setting defaults must be gated whenExtensionDisabled(fof-gamification)');

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

$postPolicy = (string) file_get_contents($root.'/src/Voting/PostVotePolicy.php');
str_contains($postPolicy, 'forceDeny()')
    ? pass('POST_VOTE_POLICY_UNSAFE_RESULT=FORCE_DENY')
    : fail('PostVotePolicy must forceDeny');
! preg_match('/\$this->allow\(/', $postPolicy)
    ? pass('FLATRATE_POLICY_FORCE_ALLOW=false')
    : fail('PostVotePolicy must not allow');

$globalSrc = (string) file_get_contents($root.'/src/Voting/GlobalVotingPolicy.php');
str_contains($globalSrc, 'forceDeny()')
    ? pass('ORDINARY_RANKING_RESULT=FORCE_DENY')
    : fail('GlobalVotingPolicy must forceDeny');

$readySrc = (string) file_get_contents($root.'/src/Voting/VotingReadiness.php');
str_contains($readySrc, 'flatrate_vote_activity_state')
    ? pass('READINESS_ACTIVITY_PREFIX_AWARE')
    : fail('logical vote-state table missing');
str_contains($readySrc, 'permission_state_unavailable')
    ? pass('PERMISSION_STATE_UNAVAILABLE_BLOCKER_PRESENT')
    : fail('permission_state_unavailable missing');
str_contains($readySrc, 'inspection_ok')
    ? pass('PERMISSION_STATE_MODEL=TRISTATE')
    : fail('inspection_ok missing');
str_contains($readySrc, 'orPermissionStates')
    ? pass('PERMISSION_UNKNOWN_REPRESENTABLE')
    : fail('orPermissionStates missing');
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
(str_contains($pv, "app.data['fof-gamification.upVotesOnly'] = '1'")
    && str_contains($pv, "app.data['fof-gamification.iconName'] = 'thumbs'"))
    ? pass('UPVOTE_ONLY_THUMBS_UP_PRESENTATION')
    : fail('upvote-only thumbs-up presentation missing');
$forumLess = (string) file_get_contents($root.'/resources/less/forum.less');
(str_contains($forumLess, '.Post-downvote')
    && str_contains($forumLess, '.Post-voteButton--down')
    && str_contains($forumLess, 'DiscussionListItem-voteButton--down')
    && str_contains($forumLess, 'display: none !important'))
    ? pass('DOWNVOTE_CONTROL_HIDDEN')
    : fail('downvote-control fallback missing');
(str_contains($pv, 'FlatRateVotes--zero')
    && str_contains($pv, 'FlatRateVotes--hasVotes')
    && str_contains($pv, 'FlatRateVotes--mine')
    && str_contains($pv, 'decorateVoteChrome'))
    ? pass('VOTE_CHROME_STATE_CLASSES')
    : fail('vote chrome state class decoration missing');
(str_contains($forumLess, '.FlatRateVotes--zero')
    && str_contains($forumLess, '@flatrate-vote-zero: #ffffff')
    && str_contains($forumLess, '.FlatRateVotes--hasVotes:not(.FlatRateVotes--mine)')
    && str_contains($forumLess, '@flatrate-vote-has: #84cc16')
    && str_contains($forumLess, '.FlatRateVotes--mine')
    && str_contains($forumLess, '@flatrate-vote-mine: #c72d5d'))
    ? pass('VOTE_CHROME_THREE_STATE_COLORS')
    : fail('three-state vote colors missing (white / lime / pink)');
(str_contains($forumLess, 'flex-direction: row')
    && ! str_contains($forumLess, 'flex-direction: row-reverse')
    && str_contains($forumLess, '.CommentPost-votes'))
    ? pass('UPVOTE_COUNT_RIGHT_OF_THUMB')
    : fail('count-right-of-thumb layout missing');
(! str_contains($pv, 'thumbs-down')
    && ! str_contains($pv, "iconName'] = 'arrow'")
    && ! str_contains($pv, "upVotesOnly'] = '0'"))
    ? pass('NO_THUMBS_DOWN_PRESENTATION')
    : fail('thumbs-down / downvote presentation leaked into plain-voting.js');
$migrations = glob($root.'/migrations/*.php') ?: [];
$voteMigrationHit = false;
foreach ($migrations as $migration) {
    $src = (string) file_get_contents($migration);
    if (preg_match('/post_votes|drop.*votes|delete.*votes/i', $src)
        && preg_match('/Schema::(drop|table)|DB::(delete|statement)/i', $src)) {
        // Allow Activity/state companion tables; block post_votes destructive mutations.
        if (str_contains($src, 'post_votes')
            && preg_match('/drop|delete\s+from\s+[`\']?post_votes/i', $src)) {
            $voteMigrationHit = true;
            fail('NO_DATABASE_MIGRATION violated by '.basename($migration));
        }
    }
}
if (! $voteMigrationHit) {
    pass('NO_DATABASE_MIGRATION');
}
$activitySrc = is_file($root.'/src/Activity/EmitVoteActivity.php')
    ? (string) file_get_contents($root.'/src/Activity/EmitVoteActivity.php')
    : '';
(str_contains($activitySrc, 'vote_transition')
    && str_contains($activitySrc, 'from_value')
    && str_contains($activitySrc, 'to_value')
    && ! str_contains($activitySrc, 'thumb_up')
    && ! str_contains($activitySrc, 'helpful_click'))
    ? pass('NO_ACTIVITY_EVENT_REMOVAL')
    : fail('Activity vote_transition contract incomplete or replaced');
(is_file($root.'/src/Voting/VoterIdentityRelationshipGuard.php')
    && str_contains((string) file_get_contents($root.'/src/Voting/VoterIdentityRelationshipGuard.php'), 'canSeeVoters'))
    ? pass('NO_VOTER_PRIVACY_RELAXATION')
    : fail('voter privacy guard missing');
is_file($root.'/docs/growth-001b-plain-vote-foundation.md')
    ? pass('docs present')
    : fail('docs missing');


// GROWTH-001E2: voter privacy gates (also has dedicated CI step when workflow scope available)
$privacy = $root.'/test/plain-voting-voter-privacy.php';
if (! is_file($privacy)) {
    fail('plain-voting-voter-privacy.php missing');
} else {
    passthru('php '.escapeshellarg($privacy), $privacyCode);
    if ($privacyCode !== 0) {
        fail('GROWTH-001E2 voter privacy gates');
    } else {
        pass('GROWTH-001E2_VOTER_PRIVACY_GATES');
    }
}

echo 'plain-voting-foundation.php: '.($failures === 0 ? 'all checks passed' : "{$failures} failure(s)")."\n";
exit($failures === 0 ? 0 : 1);
