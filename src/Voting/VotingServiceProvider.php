<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Api\Serializer\PostSerializer;
use Flarum\Extend;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

final class VotingServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(VotingReadiness::class);
        $this->container->singleton(VoteSafetyGate::class);
        $this->container->singleton(VotingReadinessController::class);
        $this->container->singleton(PostVotePolicy::class);
        $this->container->singleton(GlobalVotingPolicy::class);
        $this->container->singleton(VoterIdentityRelationshipGuard::class);
        $this->container->singleton(DiscussionVoteSummary::class);
        $this->container->singleton(EnforceOneBallotPerDiscussion::class);
    }

    /**
     * Re-assert PostSerializer upvotes/downvotes after FoF extender registration.
     *
     * Flarum Application::boot() order:
     * 1) booting callbacks → ExtensionManager::extend (FoF hasMany)
     * 2) boot all service providers (this method) → FlatRate override wins
     */
    public function boot(Dispatcher $events): void
    {
        /** @var VoterIdentityRelationshipGuard $guard */
        $guard = $this->container->make(VoterIdentityRelationshipGuard::class);

        (new Extend\ApiSerializer(PostSerializer::class))
            ->relationship(
                'upvotes',
                function (PostSerializer $serializer, Post $post) use ($guard) {
                    return $guard->relationship($serializer, $post, 'upvotes');
                }
            )
            ->relationship(
                'downvotes',
                function (PostSerializer $serializer, Post $post) use ($guard) {
                    return $guard->relationship($serializer, $post, 'downvotes');
                }
            )
            ->extend($this->container);

        $guard->markRegistered();

        // Soft-bind FoF vote seam for one-ballot-per-discussion enforcement.
        $eventClass = 'FoF\\Gamification\\Events\\PostWasVoted';
        if (class_exists($eventClass)) {
            $events->listen($eventClass, EnforceOneBallotPerDiscussion::class);
        }
    }
}
