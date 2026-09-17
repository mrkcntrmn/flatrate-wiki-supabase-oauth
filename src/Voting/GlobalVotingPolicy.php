<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Server-side neutralization of FoF public rankings for ordinary users.
 * UPSTREAM_PUBLIC_RANKINGS=false
 */
final class GlobalVotingPolicy extends AbstractPolicy
{
    public const RANKING_ABILITY = 'fof.gamification.viewRankingPage';

    /**
     * @return string|bool|null
     */
    public function can(User $actor, string $ability)
    {
        if ($ability !== self::RANKING_ABILITY) {
            return null;
        }

        if ($actor->isAdmin()) {
            // Operational inspection may remain available to admins.
            return null;
        }

        return $this->deny();
    }
}
