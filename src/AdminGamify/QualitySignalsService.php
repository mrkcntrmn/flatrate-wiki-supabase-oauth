<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use Illuminate\Database\ConnectionInterface;

/**
 * Site-wide Quality Signals over canonical FoF post_votes.
 * Not Technical Merit. No invented time windows when ballots lack timestamps.
 */
final class QualitySignalsService
{
    public const LEADERBOARD_MAX_ROWS = 100;

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function siteSummary(): array
    {
        if (! $this->postVotesTablePresent()) {
            return [
                'status' => 'unavailable',
                'error' => 'post_votes_missing',
                'time_window_support' => false,
                'time_window' => 'current_effective_state/all_time',
                'reason' => 'canonical_ballot_table_missing',
            ];
        }

        $base = $this->visibleBallotsQuery();

        $totalActive = (int) (clone $base)->where('post_votes.value', '!=', 0)->count();
        $positive = (int) (clone $base)->where('post_votes.value', '>', 0)->count();
        $negative = (int) (clone $base)->where('post_votes.value', '<', 0)->count();
        $uniqueVoters = (int) (clone $base)->where('post_votes.value', '!=', 0)->distinct('post_votes.user_id')->count('post_votes.user_id');
        $ratedPosts = (int) (clone $base)->where('post_votes.value', '!=', 0)->distinct('post_votes.post_id')->count('post_votes.post_id');
        $ratedDiscussions = (int) (clone $base)->where('post_votes.value', '!=', 0)->distinct('posts.discussion_id')->count('posts.discussion_id');
        $ratedContributors = (int) (clone $base)->where('post_votes.value', '!=', 0)->whereNotNull('posts.user_id')->distinct('posts.user_id')->count('posts.user_id');

        return [
            'status' => 'ok',
            'time_window_support' => $this->hasTrustworthyVoteTimestamp(),
            'time_window' => 'current_effective_state/all_time',
            'reason' => $this->hasTrustworthyVoteTimestamp()
                ? null
                : 'canonical_ballot_state_has_no_trustworthy_timestamp',
            'total_active_ballots' => $totalActive,
            'positive_ballots' => $positive,
            'negative_ballots' => $negative,
            'unique_voters' => $uniqueVoters,
            'rated_posts' => $ratedPosts,
            'rated_discussions' => $ratedDiscussions,
            'rated_contributors' => $ratedContributors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contributorLeaderboard(int $limit = self::LEADERBOARD_MAX_ROWS): array
    {
        $limit = max(1, min($limit, self::LEADERBOARD_MAX_ROWS));

        if (! $this->postVotesTablePresent()) {
            return [
                'status' => 'unavailable',
                'error' => 'post_votes_missing',
                'time_window_support' => false,
                'time_window' => 'current_effective_state/all_time',
                'rows' => [],
            ];
        }

        $rows = $this->visibleBallotsQuery()
            ->where('post_votes.value', '!=', 0)
            ->whereNotNull('posts.user_id')
            ->groupBy('posts.user_id')
            ->orderByRaw('COUNT(DISTINCT post_votes.post_id) DESC')
            ->orderByRaw('posts.user_id ASC')
            ->limit($limit)
            ->get([
                $this->db->raw('posts.user_id as member_number'),
                $this->db->raw('COUNT(DISTINCT post_votes.post_id) as rated_contributions'),
                $this->db->raw('SUM(CASE WHEN post_votes.value > 0 THEN 1 ELSE 0 END) as positive_ballots'),
                $this->db->raw('SUM(CASE WHEN post_votes.value < 0 THEN 1 ELSE 0 END) as negative_ballots'),
                $this->db->raw('COUNT(DISTINCT post_votes.user_id) as distinct_voters'),
                $this->db->raw('COUNT(DISTINCT posts.discussion_id) as distinct_discussions'),
                $this->db->raw('COUNT(*) as sample_size'),
            ]);

        $out = [];
        $rank = 0;
        foreach ($rows as $row) {
            $rank++;
            $memberNumber = (int) $row->member_number;
            $positive = (int) $row->positive_ballots;
            $sample = (int) $row->sample_size;
            $out[] = [
                'rank' => $rank,
                'member_number' => $memberNumber,
                'technician_nickname' => MemberIdentity::nickname($memberNumber),
                'rated_contributions' => (int) $row->rated_contributions,
                'positive_ballots' => $positive,
                'negative_ballots' => (int) $row->negative_ballots,
                'distinct_voters' => (int) $row->distinct_voters,
                'distinct_discussions' => (int) $row->distinct_discussions,
                'sample_size' => $sample,
                'positive_ratio' => $sample > 0 ? round($positive / $sample, 4) : null,
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'Quality Signals',
            'time_window_support' => $this->hasTrustworthyVoteTimestamp(),
            'time_window' => 'current_effective_state/all_time',
            'reason' => $this->hasTrustworthyVoteTimestamp()
                ? null
                : 'canonical_ballot_state_has_no_trustworthy_timestamp',
            'ranking_rule' => 'rated_contributions_desc_then_member_number_asc',
            'rows' => $out,
        ];
    }

    private function visibleBallotsQuery()
    {
        return $this->db->table('post_votes')
            ->join('posts', 'posts.id', '=', 'post_votes.post_id')
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

    private function hasTrustworthyVoteTimestamp(): bool
    {
        try {
            $schema = $this->db->getSchemaBuilder();
            if (! $schema->hasTable('post_votes')) {
                return false;
            }
            // FoF Gamification canonical ballots are current-state rows without a
            // trustworthy event timestamp for Today/7d/30d windows.
            return $schema->hasColumn('post_votes', 'created_at')
                || $schema->hasColumn('post_votes', 'voted_at');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
