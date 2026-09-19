<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * ONE_EFFECTIVE_POSITIVE_BALLOT_PER_MEMBER_PER_DISCUSSION.
 *
 * FoF uniqueness is per (user_id, post_id). After a successful positive vote,
 * serialize on the actor's user row, reassert the intended target, and zero
 * every other positive ballot in the same discussion. Soft-bound to FoF
 * PostWasVoted — no hard composer dependency.
 */
final class EnforceOneBallotPerDiscussion
{
    public function __construct(
        private ConnectionInterface $db,
        private ?VoteSafetyGate $gate,
        private LoggerInterface $logger
    ) {
    }

    public function handle(object $event): void
    {
        try {
            if ($this->gate === null || ! $this->gate->isEnabled()) {
                return;
            }

            if (! isset($event->vote)) {
                return;
            }

            $vote = $event->vote;
            $value = (int) ($vote->value ?? 0);
            if ($value <= 0) {
                return;
            }

            $post = $vote->post ?? null;
            $actor = $vote->user ?? null;
            if (! is_object($post) || ! is_object($actor)) {
                return;
            }
            if (! isset($post->id, $post->discussion_id, $actor->id)) {
                return;
            }

            $discussionId = (int) $post->discussion_id;
            $actorId = (int) $actor->id;
            $keepPostId = (int) $post->id;

            $this->reconcile($discussionId, $actorId, $keepPostId);
        } catch (\Throwable $e) {
            $this->logger->warning('flatrate_one_ballot_per_discussion_failed', [
                'error_class' => $e::class,
            ]);
        }
    }

    /**
     * Deterministic reconciliation for one actor/discussion.
     * Safe to call from tests without a full FoF event object.
     */
    public function reconcile(int $discussionId, int $actorId, int $keepPostId): void
    {
        $this->db->transaction(function () use ($discussionId, $actorId, $keepPostId) {
            // Serialize overlapping vote reconciliations for this member.
            $locked = $this->db->table('users')
                ->where('id', $actorId)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return;
            }

            $now = date('Y-m-d H:i:s');
            $firstPostId = (int) ($this->db->table('discussions')
                ->where('id', $discussionId)
                ->value('first_post_id') ?? 0);

            // Reassert the intended target as the sole positive ballot.
            $existingKeep = $this->db->table('post_votes')
                ->where('post_id', $keepPostId)
                ->where('user_id', $actorId)
                ->lockForUpdate()
                ->first();

            if ($existingKeep) {
                if ((int) $existingKeep->value !== 1) {
                    $this->db->table('post_votes')
                        ->where('id', (int) $existingKeep->id)
                        ->update([
                            'value' => 1,
                            'updated_at' => $now,
                        ]);
                }
            } else {
                $this->db->table('post_votes')->insert([
                    'post_id' => $keepPostId,
                    'user_id' => $actorId,
                    'value' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $others = $this->db->table('post_votes')
                ->join('posts', 'posts.id', '=', 'post_votes.post_id')
                ->where('posts.discussion_id', $discussionId)
                ->where('post_votes.user_id', $actorId)
                ->where('post_votes.value', '>', 0)
                ->where('post_votes.post_id', '!=', $keepPostId)
                ->lockForUpdate()
                ->get([
                    'post_votes.id',
                    'post_votes.post_id',
                    'posts.user_id as post_author_id',
                ]);

            $affectedAuthorIds = [];
            $touchedFirstPost = false;

            // Keep-target author also needs a stable cached total after moves.
            $keepAuthorId = (int) ($this->db->table('posts')
                ->where('id', $keepPostId)
                ->value('user_id') ?? 0);
            if ($keepAuthorId > 0) {
                $affectedAuthorIds[$keepAuthorId] = true;
            }

            foreach ($others as $row) {
                $this->db->table('post_votes')
                    ->where('id', (int) $row->id)
                    ->update([
                        'value' => 0,
                        'updated_at' => $now,
                    ]);

                $authorId = (int) ($row->post_author_id ?? 0);
                if ($authorId > 0) {
                    $affectedAuthorIds[$authorId] = true;
                }
                if ($firstPostId > 0 && (int) $row->post_id === $firstPostId) {
                    $touchedFirstPost = true;
                }
            }

            $this->recalculateAuthorPointsAndRanks(array_keys($affectedAuthorIds));

            if ($touchedFirstPost || ($firstPostId > 0 && $keepPostId === $firstPostId)) {
                $this->recalculateFoFDiscussionVotes($discussionId, $firstPostId);
            }
        });
    }

    /**
     * @param list<int> $authorIds
     */
    private function recalculateAuthorPointsAndRanks(array $authorIds): void
    {
        if ($authorIds === []) {
            return;
        }

        foreach ($authorIds as $authorId) {
            $points = (int) $this->db->table('post_votes')
                ->join('posts', 'posts.id', '=', 'post_votes.post_id')
                ->where('posts.user_id', $authorId)
                ->sum('post_votes.value');

            $this->db->table('users')
                ->where('id', $authorId)
                ->update(['votes' => $points]);

            $this->resyncRanks($authorId, $points);
        }
    }

    private function resyncRanks(int $userId, int $points): void
    {
        if (! $this->db->getSchemaBuilder()->hasTable('ranks')
            || ! $this->db->getSchemaBuilder()->hasTable('rank_users')) {
            return;
        }

        $rankIds = $this->db->table('ranks')
            ->where('points', '<=', $points)
            ->pluck('id')
            ->all();

        $this->db->table('rank_users')->where('user_id', $userId)->delete();

        foreach ($rankIds as $rankId) {
            $this->db->table('rank_users')->insert([
                'user_id' => $userId,
                'rank_id' => (int) $rankId,
            ]);
        }
    }

    private function recalculateFoFDiscussionVotes(int $discussionId, int $firstPostId): void
    {
        if ($firstPostId <= 0) {
            return;
        }

        $votes = (int) $this->db->table('post_votes')
            ->where('post_id', $firstPostId)
            ->sum('value');

        $this->db->table('discussions')
            ->where('id', $discussionId)
            ->update(['votes' => $votes]);
    }
}
