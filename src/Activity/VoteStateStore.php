<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\User\User;

/**
 * FlatRate-owned vote observation tracker — not a second canonical vote store.
 * FoF Gamification post_votes remains canonical.
 */
final class VoteStateStore
{
    public function observe(User $actor, int $postId, int $newValue, bool $wasRecentlyCreated): ?array
    {
        if (! in_array($newValue, [-1, 0, 1], true) || $postId <= 0) {
            return null;
        }

        $db = $actor->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $userId = (int) $actor->id;

        return $db->transaction(function () use ($db, $userId, $postId, $newValue, $wasRecentlyCreated, $now) {
            $row = $db->table('flatrate_vote_activity_state')
                ->where('post_id', $postId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                // First observation after deployment.
                if (! $wasRecentlyCreated) {
                    // Prior FoF state existed; seed baseline without falsely casting.
                    $db->table('flatrate_vote_activity_state')->insert([
                        'post_id' => $postId,
                        'user_id' => $userId,
                        'last_effective_vote' => $newValue,
                        'state_version' => 0,
                        'updated_at' => $now,
                    ]);

                    return null;
                }

                $from = 0;
                $version = 1;
                $db->table('flatrate_vote_activity_state')->insert([
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'last_effective_vote' => $newValue,
                    'state_version' => $version,
                    'updated_at' => $now,
                ]);

                if ($from === $newValue) {
                    return null;
                }

                return [
                    'from_value' => $from,
                    'to_value' => $newValue,
                    'vote_state_version' => $version,
                ];
            }

            $from = (int) $row->last_effective_vote;
            if ($from === $newValue) {
                return null;
            }

            $version = ((int) $row->state_version) + 1;
            $db->table('flatrate_vote_activity_state')
                ->where('post_id', $postId)
                ->where('user_id', $userId)
                ->update([
                    'last_effective_vote' => $newValue,
                    'state_version' => $version,
                    'updated_at' => $now,
                ]);

            return [
                'from_value' => $from,
                'to_value' => $newValue,
                'vote_state_version' => $version,
            ];
        });
    }
}
