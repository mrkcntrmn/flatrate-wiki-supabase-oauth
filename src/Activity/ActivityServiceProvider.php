<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;

final class ActivityServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SharedSecretResolver::class);
        $this->container->singleton(HmacSigner::class);
        $this->container->singleton(FlatRateSubjectResolver::class);
        $this->container->singleton(VoteStateStore::class);
        $this->container->singleton(OutboxStore::class);
        $this->container->singleton(BrandContext::class);
        $this->container->singleton(ActivityClient::class);
        $this->container->singleton(ActivityEmitter::class);
        $this->container->singleton(ActivityOutboxDrainer::class);
        $this->container->singleton(EmitVoteActivity::class);
        $this->container->singleton(EmitDiscussionCreated::class);
        $this->container->singleton(EmitReplyCreated::class);
        $this->container->singleton(EmitJobBreakdownCreated::class);
    }

    public function boot(Dispatcher $events): void
    {
        // Soft-bind FoF vote seam when package classes are present.
        if (class_exists('\\FoF\\Gamification\\Events\\PostWasVoted')) {
            $events->listen(
                '\\FoF\\Gamification\\Events\\PostWasVoted',
                EmitVoteActivity::class
            );
        }
    }
}
