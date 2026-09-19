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
     * Flarum AbstractPolicy::checkAbility invokes can($actor, $ability, $instance).
     * PHP 8 rejects a 2-arg signature when 3 args are passed — that fatals SPA boot.
     *
     * @param mixed $instance
     * @return string|bool|null
     */
    public function can(User $actor, string $ability, $instance = null)
    {
        if ($ability !== self::RANKING_ABILITY) {
            return null;
        }

        if ($actor->isAdmin()) {
            // Operational inspection may remain available to admins.
            return null;
        }

        // FORCE_DENY: FlatRate privacy boundary must beat provider FORCE_ALLOW.
        return $this->forceDeny();
    }
}
