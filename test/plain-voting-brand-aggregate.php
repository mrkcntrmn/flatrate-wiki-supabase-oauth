<?php

/**
 * FORUM-BRAND-VOTE-TOTALS-001 — structural source gates.
 */

$root = dirname(__DIR__);
$failures = 0;

function pass_brand(string $message): void
{
    fwrite(STDERR, "[PASS] {$message}\n");
}

function fail_brand(string $message): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "[FAIL] {$message}\n");
}

$summary = (string) file_get_contents($root.'/src/Voting/BrandVoteSummary.php');
$brand = (string) file_get_contents($root.'/src/Activity/BrandContext.php');
$serialize = (string) file_get_contents($root.'/src/Api/SerializeBrandVoteSummary.php');
$provider = (string) file_get_contents($root.'/src/Voting/VotingServiceProvider.php');
$extend = (string) file_get_contents($root.'/extend.php');
$pv = (string) file_get_contents($root.'/js/dist/plain-voting.js');
$less = (string) file_get_contents($root.'/resources/less/forum.less');

str_contains($summary, 'whereVisibleTo($actor)')
    && str_contains($summary, "where('post_votes.value', '>', 0)")
    && str_contains($summary, "where('posts.type', 'comment')")
    && str_contains($summary, "whereNull('posts.hidden_at')")
    ? pass_brand('ACTOR_VISIBLE_POSITIVE_VOTES')
    : fail_brand('Brand aggregate must preserve actor visibility and visible positive-post semantics');

str_contains($summary, "discussion_tag")
    && str_contains($summary, "whereNotNull('tags.position')")
    && str_contains($summary, 'brandSlugFromPrimaryTags')
    && ! str_contains($summary, 'FAMILY_CHILDREN')
    ? pass_brand('CANONICAL_EXACT_BRAND_ATTRIBUTION')
    : fail_brand('Brand aggregate must reuse canonical primary-Brand resolver');

str_contains($summary, 'groupBy')
    && ! str_contains($summary, 'foreach ($totals')
    ? pass_brand('BOUNDED_GROUPED_READ')
    : fail_brand('Brand aggregate must avoid per-Brand vote queries');

str_contains($serialize, 'flatRateBrandUpvotes')
    && str_contains($serialize, '$totals !== null')
    ? pass_brand('FAIL_CLOSED_SERIALIZATION')
    : fail_brand('Brand totals must be omitted when read model unavailable');

str_contains($provider, 'BrandVoteSummary::class')
    && str_contains($extend, 'SerializeBrandVoteSummary::class')
    && str_contains($extend, 'ForumSerializer::class')
    ? pass_brand('FORUM_BOOTSTRAP_REGISTRATION')
    : fail_brand('Brand aggregate must be registered on ForumSerializer');

foreach (['aston-martin', 'jlr', 'land-rover', 'range-rover'] as $slug) {
    if (! str_contains($brand, "'{$slug}' => true")) {
        fail_brand("missing canonical Brand slug {$slug}");
    }
}
str_contains($brand, "'jaguar' => 'jlr'")
    && str_contains($brand, "'land-rover' => 'jlr'")
    && str_contains($brand, "'range-rover' => 'jlr'")
    ? pass_brand('JLR_CANONICAL_CONTEXT')
    : fail_brand('JLR family mapping missing');

str_contains($pv, "flatRateDiscussionUpvotes")
    && str_contains($pv, 'FlatRateVotes--discussionAggregate')
    && str_contains($pv, 'refreshBrandVoteTotals')
    && str_contains($pv, 'flatRateBrandUpvotes')
    ? pass_brand('CLIENT_WHOLE_DISCUSSION_AND_BRAND_REFRESH')
    : fail_brand('board rows/Brand totals client projection missing');

str_contains($pv, 'FlatRateDiscussionListTotal')
    && str_contains($pv, 'count > 0')
    && str_contains($less, '.DiscussionListItem-title .FlatRateDiscussionListTotal')
    && str_contains($less, '.DiscussionListItem-votes.FlatRateVotes--discussionAggregate')
    && str_contains($less, 'display: none !important')
    && str_contains($less, 'color: @flatrate-vote-has !important')
    ? pass_brand('BOARD_ROW_INLINE_POSITIVE_TOTAL_ONLY')
    : fail_brand('board whole-discussion total must render beside the title and omit zero totals');

$decorateCall = strpos($pv, 'decorateDiscussionListTotal(root, model);');
$votesGuard = strpos($pv, 'if (!votesEl)');
$decorateCall !== false
    && $votesGuard !== false
    && $decorateCall < $votesGuard
    && str_contains($pv, "root.querySelector('.DiscussionListItem-title')")
    && str_contains($pv, "model.attribute('flatRateDiscussionUpvotes')")
    && str_contains($pv, 'function decorateDiscussionListTotal')
    ? pass_brand('BOARD_ROW_TOTAL_INDEPENDENT_OF_FOF_LIST_NODE')
    : fail_brand(
        'board-row aggregate must project before optional FoF votes-node guard'
    );

if ($failures > 0) {
    fwrite(STDERR, "plain-voting-brand-aggregate.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "plain-voting-brand-aggregate.php: all checks passed\n");
exit(0);
