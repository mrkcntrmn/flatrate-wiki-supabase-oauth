<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * FlatRate whole-discussion upvote aggregate over FoF post_votes.
 *
 * Do NOT use FoF discussion.votes — that is first-post-only.
 */
final class DiscussionVoteSummary
{
    public function __construct(
        private ConnectionInterface $db,
        private VoteSafetyGate $gate
    ) {
    }

    /**
     * @return array{
     *   flatRateDiscussionUpvotes: int,
     *   flatRateDiscussionViewerUpvoted: bool,
     *   flatRateDiscussionViewerVotePostId: int|null,
     *   flatRateDiscussionCanUpvote: bool
     * }
     */
    public function forDiscussion(Discussion $discussion, ?User $actor): array
    {
        if (! $this->gate->isEnabled() || ! $this->postVotesTablePresent()) {
            return $this->empty();
        }

        $discussionId = (int) $discussion->id;
        $total = $this->positiveVoteCount($discussionId);

        $viewerUpvoted = false;
        $viewerVotePostId = null;
        $canUpvote = false;

        if ($actor && ! $actor->isGuest()) {
            $viewerVotePostId = $this->viewerPositiveVotePostId($discussionId, (int) $actor->id);
            $viewerUpvoted = $viewerVotePostId !== null;
            // Clickability is "no ballot yet". First-post mutation policy still
            // runs on the vote API (self-vote / gate / FoF permissions).
            $canUpvote = ! $viewerUpvoted;
        }

        return [
            'flatRateDiscussionUpvotes' => $total,
            'flatRateDiscussionViewerUpvoted' => $viewerUpvoted,
            'flatRateDiscussionViewerVotePostId' => $viewerVotePostId,
            'flatRateDiscussionCanUpvote' => $canUpvote,
        ];
    }

    public function positiveVoteCount(int $discussionId): int
    {
        return (int) $this->visiblePositiveVotesQuery($discussionId)->count();
    }

    public function viewerPositiveVotePostId(int $discussionId, int $userId): ?int
    {
        $postId = $this->visiblePositiveVotesQuery($discussionId)
            ->where('post_votes.user_id', $userId)
            ->orderBy('post_votes.post_id')
            ->value('post_votes.post_id');

        return $postId === null ? null : (int) $postId;
    }

    /**
     * Positive votes on visible comment posts in a discussion.
     * Excludes hidden posts so soft-hidden content cannot inflate the total.
     */
    private function visiblePositiveVotesQuery(int $discussionId)
    {
        return $this->db->table('post_votes')
            ->join('posts', 'posts.id', '=', 'post_votes.post_id')
            ->where('posts.discussion_id', $discussionId)
            ->where('post_votes.value', '>', 0)
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at');
    }

    private function postVotesTablePresent(): bool
    {
        try {
            return $this->db->getSchemaBuilder()->hasTable('post_votes');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{
     *   flatRateDiscussionUpvotes: int,
     *   flatRateDiscussionViewerUpvoted: bool,
     *   flatRateDiscussionViewerVotePostId: int|null,
     *   flatRateDiscussionCanUpvote: bool
     * }
     */
    private function empty(): array
    {
        return [
            'flatRateDiscussionUpvotes' => 0,
            'flatRateDiscussionViewerUpvoted' => false,
            'flatRateDiscussionViewerVotePostId' => null,
            'flatRateDiscussionCanUpvote' => false,
        ];
    }
}
