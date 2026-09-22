<?php

/**
 * GROWTH-001UI discussion aggregate upvote — structural source gates.
 */

$root = dirname(__DIR__);
$failures = 0;

function pass(string $message): void
{
    fwrite(STDERR, "[PASS] {$message}\n");
}

function fail(string $message): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "[FAIL] {$message}\n");
}

$summary = (string) file_get_contents($root.'/src/Voting/DiscussionVoteSummary.php');
$serialize = (string) file_get_contents($root.'/src/Api/SerializeDiscussionVoteSummary.php');
$provider = (string) file_get_contents($root.'/src/Voting/VotingServiceProvider.php');
$extend = (string) file_get_contents($root.'/extend.php');
$pv = (string) file_get_contents($root.'/js/dist/plain-voting.js');
$less = (string) file_get_contents($root.'/resources/less/forum.less');
$docs = (string) file_get_contents($root.'/docs/growth-001b-plain-vote-foundation.md');

str_contains($summary, 'visiblePositiveVotesQuery')
    && str_contains($summary, "where('post_votes.value', '>', 0)")
    && str_contains($summary, 'whereNull(\'posts.hidden_at\')')
    && str_contains($summary, "where('posts.discussion_id', \$discussionId)")
    ? pass('DISCUSSION_TOTAL_ALL_POSTS_QUERY')
    : fail('discussion aggregate must count all visible positive post_votes');

// Aggregate total must come from joined post_votes, not FoF discussion.votes /
// first_post_id. first_post_id may only appear in canUpvote gating.
! str_contains($summary, 'updateDiscussionVotes')
    && ! preg_match('/visiblePositiveVotesQuery[\s\S]{0,400}first_post_id/', $summary)
    && str_contains($summary, "where('posts.discussion_id', \$discussionId)")
    ? pass('AGGREGATE_NOT_FOF_DISCUSSION_VOTES')
    : fail('aggregate must not use FoF first-post discussion.votes');

str_contains($serialize, 'flatRateDiscussionUpvotes')
    && str_contains($serialize, 'flatRateDiscussionViewerUpvoted')
    && str_contains($serialize, 'flatRateDiscussionViewerVotePostId')
    && str_contains($serialize, 'flatRateDiscussionCanUpvote')
    && ! str_contains($serialize, 'upvotes')
    ? pass('DISCUSSION_SUMMARY_ATTRIBUTES')
    : fail('bounded discussion summary attributes missing');

! str_contains($provider, 'EnforceOneBallotPerDiscussion')
    && ! str_contains($provider, 'PostWasVoted')
    && str_contains($provider, 'DiscussionVoteSummary')
    ? pass('MULTI_POST_BALLOTS_PRESERVED')
    : fail('FlatRate must not zero a member\'s other positive post votes');


str_contains($extend, 'BasicDiscussionSerializer')
    && str_contains($extend, 'SerializeDiscussionVoteSummary')
    ? pass('EXTEND_REGISTERS_DISCUSSION_SUMMARY')
    : fail('extend.php must register BasicDiscussionSerializer attributes');

str_contains($pv, 'FlatRateDiscussionVote')
    && str_contains($pv, 'flatRateDiscussionVote')
    && str_contains($pv, 'sidebarItems')
    && str_contains($pv, "save([true, false, 'vote'])")
    && str_contains($pv, 'refreshDiscussionSummary')
    ? pass('DISCUSSION_HEADER_CONTROL_JS')
    : fail('plain-voting.js discussion header control missing');

str_contains($less, '.FlatRateDiscussionVote')
    && str_contains($less, 'FlatRateDiscussionVote--available')
    && str_contains($less, 'FlatRateDiscussionVote--mine')
    && str_contains($less, 'item-flatRateDiscussionVote')
    && str_contains($less, 'flex-direction: row')
    ? pass('DISCUSSION_HEADER_LAYOUT_CSS')
    : fail('discussion header CSS missing');

str_contains($docs, 'DISCUSSION_UPVOTE_TOTAL')
    && str_contains($docs, 'MULTIPLE_POSITIVE_BALLOTS_PER_MEMBER_PER_DISCUSSION=true')
    && str_contains($docs, 'not FoF discussion.votes')
    ? pass('DOCS_DISTINGUISH_AGGREGATE')
    : fail('docs must distinguish discussion aggregate from FoF votes');

if ($failures > 0) {
    fwrite(STDERR, "plain-voting-discussion-aggregate.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "plain-voting-discussion-aggregate.php: all checks passed\n");
exit(0);
