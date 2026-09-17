<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Foundation\AbstractServiceProvider;

final class VotingServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(VotingReadiness::class);
        $this->container->singleton(VoteSafetyGate::class);
        $this->container->singleton(VotingReadinessController::class);
        $this->container->singleton(PostVotePolicy::class);
        $this->container->singleton(GlobalVotingPolicy::class);
    }
}
