<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Post\Post;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Additional Post policy registered only when fof-gamification is enabled.
 * FORCE_DENY when FlatRate safety fails; otherwise abstain for FoF policy.
 */
final class PostVotePolicy extends AbstractPolicy
{
    public function __construct(private VoteSafetyGate $gate)
    {
    }

    /**
     * @return string|bool|null
     */
    public function vote(User $actor, Post $post)
    {
        if (! $this->gate->allowsVoteMutation($actor, $post)) {
            // FORCE_DENY so FlatRate safety remains authoritative even if another
            // provider policy later returns FORCE_ALLOW.
            return $this->forceDeny();
        }

        // Abstain — FoF PostPolicy continues evaluating discussion.votePosts etc.
        // FLATRATE_POLICY_FORCE_ALLOW=false — never allow() / forceAllow() here.
        return null;
    }
}
