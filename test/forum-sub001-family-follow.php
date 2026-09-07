<?php

/**
 * FORUM-SUB-001 — GM/CDJR family follow notification inheritance gates.
 *
 * Pure-domain + static/source contracts. Does not boot Flarum or touch production.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root.'/src/Subscription/TagFamilyRegistry.php';
require_once $root.'/src/Subscription/EffectiveTagSubscriptionResolver.php';
require_once $root.'/src/Subscription/FollowTagsFamilyRecipientEvaluator.php';

use FlatRate\SupabaseOAuth\Subscription\EffectiveTagSubscriptionResolver;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyRecipientEvaluator;
use FlatRate\SupabaseOAuth\Subscription\TagFamilyRegistry;

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

$registry = new TagFamilyRegistry();
$resolver = new EffectiveTagSubscriptionResolver($registry);
$evaluator = new FollowTagsFamilyRecipientEvaluator($registry, $resolver);

// --- Tag family registry ---
$rootMap = [
    'gm' => 'gm',
    'buick' => 'gm',
    'cadillac' => 'gm',
    'chevrolet' => 'gm',
    'gmc' => 'gm',
    'cdjr' => 'cdjr',
    'chrysler' => 'cdjr',
    'dodge' => 'cdjr',
    'jeep' => 'cdjr',
    'ram' => 'cdjr',
];
foreach ($rootMap as $slug => $rootSlug) {
    assertSame($rootSlug, $registry->familyRootFor($slug), "familyRootFor({$slug})={$rootSlug}");
}
foreach (['toyota', 'ford', 'audi', 'start-here'] as $slug) {
    assertSame(null, $registry->familyRootFor($slug), "familyRootFor({$slug})=null");
}
assertSame(2, $registry->familyRootCount(), 'FAMILY_ROOT_COUNT=2');
assertSame(8, $registry->familyChildCount(), 'FAMILY_CHILD_COUNT=8');
assertSame(10, $registry->familyMemberCount(), 'FAMILY_MEMBER_COUNT=10');
assertSame(0, $registry->familyOverlapCount(), 'FAMILY_OVERLAP_COUNT=0');

// --- Effective subscription matrix ---
$childCases = [
    [null, 'follow', 'follow'],
    [null, 'lurk', 'lurk'],
    [null, 'ignore', 'ignore'],
    [null, 'hide', 'hide'],
    ['follow', 'lurk', 'follow'],
    ['lurk', 'follow', 'lurk'],
    ['ignore', 'follow', 'ignore'],
    ['hide', 'follow', 'hide'],
    ['follow', 'ignore', 'follow'],
    ['lurk', 'ignore', 'lurk'],
];
foreach ($childCases as [$direct, $rootSub, $expected]) {
    $got = $resolver->resolve('chevrolet', $direct, $rootSub);
    $d = var_export($direct, true);
    assertSame($expected, $got, "chevrolet direct={$d} root={$rootSub} => {$expected}");
}
assertSame(null, $resolver->resolve('gm', null, 'follow'), 'root tag has no fallback');
assertSame('follow', $resolver->resolve('toyota', 'follow', 'lurk'), 'non-family ignores root arg');
assertSame(null, $resolver->normalize('not_follow'), 'not_follow treated as null');

// Tag id map for evaluator tests
$tags = [
    'gm' => ['id' => 1, 'slug' => 'gm'],
    'chevrolet' => ['id' => 2, 'slug' => 'chevrolet'],
    'buick' => ['id' => 3, 'slug' => 'buick'],
    'cadillac' => ['id' => 4, 'slug' => 'cadillac'],
    'gmc' => ['id' => 5, 'slug' => 'gmc'],
    'cdjr' => ['id' => 6, 'slug' => 'cdjr'],
    'jeep' => ['id' => 7, 'slug' => 'jeep'],
    'ram' => ['id' => 8, 'slug' => 'ram'],
    'toyota' => ['id' => 9, 'slug' => 'toyota'],
];
$slugToId = array_map(fn ($t) => $t['id'], $tags);

function eligible(
    FollowTagsFamilyRecipientEvaluator $evaluator,
    string $mode,
    array $discussionTagKeys,
    array $tags,
    array $slugToId,
    array $subsBySlug,
    array $opts = []
): bool {
    $discussionTags = array_map(fn ($k) => $tags[$k], $discussionTagKeys);
    $userSubs = [];
    foreach ($subsBySlug as $slug => $state) {
        $userSubs[$slugToId[$slug]] = $state;
    }

    return $evaluator->isEligible(
        $mode,
        $opts['user_id'] ?? 10,
        $opts['exclude'] ?? 1,
        $discussionTags,
        $userSubs,
        $slugToId,
        $opts['discussion_visible'] ?? true,
        $opts['post_visible'] ?? true,
        $opts['is_reader'] ?? true,
        $opts['last_read'] ?? null,
        $opts['last_post_number'] ?? null
    );
}

$ND = FollowTagsFamilyRecipientEvaluator::MODE_NEW_DISCUSSION;
$NP = FollowTagsFamilyRecipientEvaluator::MODE_NEW_POST;
$NT = FollowTagsFamilyRecipientEvaluator::MODE_NEW_DISCUSSION_TAG;

// --- New discussion ---
assertTrue(eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow']), 'GM follow + Chevrolet discussion => yes');
assertTrue(eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk']), 'GM lurk + Chevrolet discussion => yes');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'ignore']), 'GM ignore + Chevrolet discussion => no');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'hide']), 'GM hide + Chevrolet discussion => no');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow', 'chevrolet' => 'ignore']), 'GM follow + Chevrolet ignore => no');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow', 'chevrolet' => 'hide']), 'GM follow + Chevrolet hide => no');
assertTrue(eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk', 'chevrolet' => 'follow']), 'GM lurk + Chevrolet follow => yes (new discussion)');
assertTrue(eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['chevrolet' => 'follow']), 'direct Chevrolet follow, GM null => yes');
assertTrue(!eligible($evaluator, $ND, ['buick'], $tags, $slugToId, ['chevrolet' => 'follow']), 'Chevrolet follow does not affect Buick');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow'], ['user_id' => 1, 'exclude' => 1]), 'discussion author => no');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow'], ['discussion_visible' => false]), 'invisible discussion => no');
assertTrue(!eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow'], ['post_visible' => false]), 'invisible first post => no');
assertTrue(eligible($evaluator, $ND, ['gm'], $tags, $slugToId, ['gm' => 'follow']), 'GM follow + GM discussion => ordinary direct');
assertTrue(!eligible($evaluator, $ND, ['gm'], $tags, $slugToId, ['buick' => 'follow']), 'Buick follow does not imply GM discussion');
assertTrue(!eligible($evaluator, $ND, ['jeep'], $tags, $slugToId, ['gm' => 'follow']), 'GM follow + Jeep => no cross-family');
assertTrue(eligible($evaluator, $ND, ['jeep'], $tags, $slugToId, ['cdjr' => 'follow']), 'CDJR follow + Jeep => yes');
assertTrue(eligible($evaluator, $ND, ['toyota'], $tags, $slugToId, ['toyota' => 'follow']), 'non-family Toyota uses direct only');

// --- Reply (caught-up: last_read >= lastPostNumber - 1) ---
// post.number=5 → lastPostNumber=4 → need last_read >= 3
$replyOpts = ['last_read' => 3, 'last_post_number' => 4];
assertTrue(eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk'], $replyOpts), 'GM lurk + Chevrolet reply + caught-up => yes');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow'], $replyOpts), 'GM follow + Chevrolet reply => no');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk', 'chevrolet' => 'follow'], $replyOpts), 'GM lurk + Chevrolet follow => no reply');
assertTrue(eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow', 'chevrolet' => 'lurk'], $replyOpts), 'GM follow + Chevrolet lurk + caught-up => yes');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk', 'chevrolet' => 'ignore'], $replyOpts), 'GM lurk + Chevrolet ignore => no');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk', 'chevrolet' => 'hide'], $replyOpts), 'GM lurk + Chevrolet hide => no');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk'], $replyOpts + ['user_id' => 1, 'exclude' => 1]), 'reply author => no');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk'], ['last_read' => 2, 'last_post_number' => 4]), 'not caught-up => no');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk'], $replyOpts + ['is_reader' => false]), 'not a reader => no');
assertTrue(!eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk'], $replyOpts + ['post_visible' => false]), 'invisible post => no');
assertTrue(eligible($evaluator, $NP, ['ram'], $tags, $slugToId, ['cdjr' => 'lurk'], $replyOpts), 'CDJR lurk + Ram reply + caught-up => yes');

// --- Re-tag ---
assertTrue(eligible($evaluator, $NT, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow']), 'GM follow + re-tag Chevrolet => yes');
assertTrue(!eligible($evaluator, $NT, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow', 'chevrolet' => 'ignore']), 'GM follow + Chevrolet ignore re-tag => no');
assertTrue(eligible($evaluator, $NT, ['jeep'], $tags, $slugToId, ['cdjr' => 'follow']), 'CDJR follow + re-tag Jeep => yes');

// --- Dedupe conceptual (evaluator returns bool; syncer dedupes by user id) ---
assertTrue(eligible($evaluator, $ND, ['chevrolet'], $tags, $slugToId, ['gm' => 'follow', 'chevrolet' => 'follow']), 'GM follow + Chevrolet follow => one recipient');
assertTrue(eligible($evaluator, $NP, ['chevrolet'], $tags, $slugToId, ['gm' => 'lurk', 'chevrolet' => 'lurk'], $replyOpts), 'GM lurk + Chevrolet lurk => one recipient');

// --- Non-family parity ---
assertTrue(!$evaluator->involvesFamily([$tags['toyota']]), 'NON_FAMILY involvesFamily=false');
assertTrue($evaluator->involvesFamily([$tags['chevrolet']]), 'family child involvesFamily=true');

// --- Mentions ---
assertTrue($evaluator->shouldSuppressMention([$tags['chevrolet']], [1 => 'ignore'], $slugToId), 'GM ignore + Chevrolet null => mention suppressed');
assertTrue(!$evaluator->shouldSuppressMention([$tags['chevrolet']], [1 => 'ignore', 2 => 'follow'], $slugToId), 'GM ignore + Chevrolet follow => mention not suppressed');
assertTrue($evaluator->shouldSuppressMention([$tags['chevrolet']], [1 => 'follow', 2 => 'ignore'], $slugToId), 'GM follow + Chevrolet ignore => mention suppressed');
assertTrue(!$evaluator->shouldSuppressMention([$tags['toyota']], [1 => 'ignore'], $slugToId), 'GM ignore + Toyota => no effect');

// --- Fixtures / static contracts ---
$syncer = file_get_contents($root.'/test/fixtures/flarum-1.8.19-NotificationSyncer.php');
$conditional = file_get_contents($root.'/test/fixtures/flarum-1.8.19-Extend-Conditional.php');
$nsp = file_get_contents($root.'/test/fixtures/flarum-1.8.19-NotificationServiceProvider.php');
$sums = file_get_contents($root.'/test/fixtures/SHA256SUMS.txt');

assertTrue(is_file($root.'/test/fixtures/flarum-1.8.19-NotificationSyncer.php'), 'NotificationSyncer fixture present');
assertTrue(str_contains($sums, hash('sha256', $syncer)), 'NotificationSyncer SHA256');
assertTrue(is_file($root.'/test/fixtures/flarum-1.8.19-Extend-Conditional.php'), 'Conditional fixture present');
assertTrue(str_contains($sums, hash('sha256', $conditional)), 'Conditional SHA256');
assertTrue(is_file($root.'/test/fixtures/flarum-1.8.19-NotificationServiceProvider.php'), 'NotificationServiceProvider fixture present');
assertTrue(!preg_match('/bind\(\s*NotificationSyncer::class/', $nsp), 'Flarum 1.8.19 NotificationServiceProvider does not bind NotificationSyncer::class');

// beforeSending after reconciliation
$callbackInvoke = strpos($syncer, 'foreach (static::$beforeSendingCallbacks as $callback)');
$newRecipientsLoop = strpos($syncer, '$newRecipients[]');
assertTrue(
    $callbackInvoke !== false && $newRecipientsLoop !== false && $callbackInvoke > $newRecipientsLoop,
    'beforeSending runs after newRecipients reconciliation loop'
);
assertTrue(str_contains($syncer, 'foreach ($users as $user)'), 'upstream sync reconciles $users before beforeSending callbacks run');

$extend = file_get_contents($root.'/extend.php');
assertTrue(str_contains($extend, "whenExtensionEnabled('fof-follow-tags'"), 'FOLLOW_TAGS_CONDITIONAL_REGISTRATION');
assertTrue(str_contains($extend, 'FollowTagsFamilyServiceProvider::class'), 'FollowTagsFamilyServiceProvider registered conditionally');
assertTrue(str_contains($extend, 'FilterInheritedIgnoredTagMentions::class'), 'inherited ignore mention filter registered');
assertTrue(str_contains($extend, 'DeliverableEmailNotificationDriver::class'), 'FORUM_EMAIL002_DRIVER_PRESERVED');

// Anti-fanout: family feature classes must not write tag_user
$familyFiles = glob($root.'/src/Subscription/*.php') ?: [];
$writeHits = 0;
foreach ($familyFiles as $file) {
    $src = file_get_contents($file);
    if (preg_match('/\b(insert|update|delete)\b.*tag_user|tag_user.*(insert|update|delete)|->save\s*\(|TagState::.*create|insert\(/i', $src)) {
        // Allow comments mentioning the invariant.
        $withoutComments = preg_replace('/\/\/.*$/m', '', $src);
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $withoutComments);
        if (preg_match('/\b(insertInto|update\(|delete\(|->save\s*\()/i', $withoutComments) && preg_match('/tag_user|TagState/i', $withoutComments)) {
            $writeHits++;
        }
    }
}
assertSame(0, $writeHits, 'FAMILY_FEATURE_TAG_USER_WRITE_REFERENCE_COUNT=0');

$ndView = file_get_contents($root.'/views/fof-follow-tags/emails/newDiscussion.blade.php');
$npView = file_get_contents($root.'/views/fof-follow-tags/emails/newPost.blade.php');
assertTrue(!str_contains($ndView, 'post_content'), 'newDiscussion.blade.php no post_content');
assertTrue(!str_contains($ndView, '{post}'), 'newDiscussion.blade.php no {post}');
assertTrue(!str_contains($npView, 'post_content'), 'newPost.blade.php no post_content');
assertTrue(!str_contains($npView, '{post}'), 'newPost.blade.php no {post}');

$syncerImpl = file_get_contents($root.'/src/Subscription/FamilyAwareNotificationSyncer.php');
assertTrue(str_contains($syncerImpl, 'extends NotificationSyncer'), 'NOTIFICATION_SYNCER_BASE_CLASS=Flarum\\Notification\\NotificationSyncer');
assertTrue(preg_match('/\$resolved\s*=\s*\$this->resolver->resolve[\s\S]*\$this->syncWithParent\(\s*\$blueprint\s*,\s*\$resolved\s*\)/s', $syncerImpl) === 1, 'FAMILY_RECIPIENT_RESOLUTION_HAPPENS_BEFORE_PARENT_SYNC');
$syncerImplCode = preg_replace('/\/\*.*?\*\//s', '', preg_replace('/\/\/.*$/m', '', $syncerImpl));
assertTrue(!str_contains($syncerImplCode, 'beforeSending'), 'syncer implementation does not invoke beforeSending to add recipients');
assertTrue(str_contains($syncerImpl, 'parent::sync($blueprint, $users)'), 'syncWithParent delegates to parent::sync');

$resolverSrc = file_get_contents($root.'/src/Subscription/FollowTagsFamilyRecipientResolver.php');
assertTrue(preg_match('/\$discussionVisible\s*=\s*false/', $resolverSrc) === 1, 'DISCUSSION_VISIBILITY_EXCEPTION_FAILS_CLOSED default');
assertTrue(preg_match('/\$postVisible\s*=\s*false/', $resolverSrc) === 1, 'POST_VISIBILITY_EXCEPTION_FAILS_CLOSED default');
assertTrue(!preg_match('/catch\s*\([^)]*Throwable[^)]*\)\s*\{\s*\$discussionVisible\s*=\s*true/', $resolverSrc), 'no discussion fail-open');
assertTrue(!preg_match('/catch\s*\([^)]*Throwable[^)]*\)\s*\{\s*\$postVisible\s*=\s*true/', $resolverSrc), 'no post fail-open');
assertTrue(str_contains($resolverSrc, 'function isDiscussionVisibleTo'), 'discussion visibility helper present');
assertTrue(str_contains($resolverSrc, 'function isPostVisibleTo'), 'post visibility helper present');

assertTrue(class_exists(TagFamilyRegistry::class), 'registry loadable');
assertTrue(!class_exists('FoF\\FollowTags\\Notifications\\NewDiscussionBlueprint', false), 'FoF blueprints not required at boot for pure tests');

// Binding contract (source-level only — runtime proof is forum-sub001-runtime.php)
$provider = file_get_contents($root.'/src/Subscription/FollowTagsFamilyServiceProvider.php');
assertTrue(str_contains($provider, 'NotificationSyncer::class'), 'NOTIFICATION_SYNCER_BINDING source marker');
assertTrue(str_contains($provider, 'FamilyAwareNotificationSyncer'), 'NotificationSyncer resolves FamilyAware subclass');
assertTrue(str_contains($provider, 'FamilyUserLookup::class'), 'FamilyUserLookup registered');

if ($failures > 0) {
    fwrite(STDERR, "forum-sub001-family-follow.php: {$failures} failure(s)\n");
    exit(1);
}

echo "forum-sub001-family-follow.php: all checks passed\n";
echo "TAG_FAMILY_REGISTRY_TEST=PASS\n";
echo "EFFECTIVE_SUBSCRIPTION_TEST=PASS\n";
echo "NEW_DISCUSSION_FAMILY_RECIPIENT_TEST=PASS\n";
echo "NEW_POST_FAMILY_RECIPIENT_TEST=PASS\n";
echo "NEW_DISCUSSION_TAG_FAMILY_RECIPIENT_TEST=PASS\n";
echo "INHERITED_IGNORE_MENTION_TEST=PASS\n";
echo "FAMILY_RECIPIENT_DEDUPE_TEST=PASS\n";
echo "NON_FAMILY_RECIPIENT_PARITY_TEST=PASS\n";
echo "DISCUSSION_VISIBILITY_EXCEPTION_FAILS_CLOSED=true\n";
echo "POST_VISIBILITY_EXCEPTION_FAILS_CLOSED=true\n";
echo "NOTE=runtime binding/resolver proofs are in test/forum-sub001-runtime.php\n";
