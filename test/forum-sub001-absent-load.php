<?php

/**
 * FORUM-SUB-001B — FoF Follow Tags absent/disabled companion load proof.
 *
 * Loads FlatRate family pure-domain classes without a FoF Follow Tags autoloader.
 * Does not boot Flarum and does not install fof/follow-tags.
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

assert(!class_exists('FoF\\FollowTags\\Notifications\\NewDiscussionBlueprint', false), 'precondition: FoF blueprint not loaded');

require_once $root.'/src/Subscription/TagFamilyRegistry.php';
require_once $root.'/src/Subscription/EffectiveTagSubscriptionResolver.php';
require_once $root.'/src/Subscription/FollowTagsFamilyRecipientEvaluator.php';

use FlatRate\SupabaseOAuth\Subscription\EffectiveTagSubscriptionResolver;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyRecipientEvaluator;
use FlatRate\SupabaseOAuth\Subscription\TagFamilyRegistry;

$registry = new TagFamilyRegistry();
$resolver = new EffectiveTagSubscriptionResolver($registry);
$evaluator = new FollowTagsFamilyRecipientEvaluator($registry, $resolver);

if ($registry->familyRootFor('chevrolet') !== 'gm') {
    fail('registry works without FoF');
} else {
    pass('registry works without FoF');
}

if ($evaluator->involvesFamily([['id' => 2, 'slug' => 'chevrolet']]) !== true) {
    fail('evaluator works without FoF');
} else {
    pass('evaluator works without FoF');
}

foreach (glob($root.'/src/Subscription/*.php') ?: [] as $file) {
    $src = file_get_contents($file);
    if (preg_match('/^use\\s+FoF\\\\FollowTags\\\\/m', $src)) {
        fail(basename($file).' hard-imports FoF Follow Tags');
    }
}
pass('no Subscription class hard-imports FoF Follow Tags');

$extend = file_get_contents($root.'/extend.php');
if (!str_contains($extend, "whenExtensionEnabled('fof-follow-tags'")) {
    fail('conditional FoF registration missing');
} else {
    pass('family integration gated on fof-follow-tags extension');
}

assert(!class_exists('FoF\\FollowTags\\Notifications\\NewDiscussionBlueprint', false), 'FoF blueprint still not loaded');
pass('FOLLOW_TAGS_ABSENT_COMPANION_LOAD_TEST');

if ($failures > 0) {
    fwrite(STDERR, "forum-sub001-absent-load.php: {$failures} failure(s)\n");
    exit(1);
}

echo "forum-sub001-absent-load.php: all checks passed\n";
echo "FOLLOW_TAGS_ABSENT_COMPANION_LOAD_TEST=PASS\n";
