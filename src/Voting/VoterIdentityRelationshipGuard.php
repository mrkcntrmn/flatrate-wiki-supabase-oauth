<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Post\Post;
use Tobscure\JsonApi\Relationship;
use Throwable;

/**
 * Gate PostSerializer upvotes/downvotes on canSeeVoters (discussion AND post).
 *
 * FoF 1.6.12 registers unguarded hasMany relationships; FlatRate overrides them
 * during VotingServiceProvider::boot() after extension extenders run.
 * Privacy does not depend on flatrate-voting.enabled.
 */
final class VoterIdentityRelationshipGuard
{
    private bool $registered = false;

    public function markRegistered(): void
    {
        $this->registered = true;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * @return Relationship|null Null = fail-closed (hide relationship).
     */
    public function relationship(PostSerializer $serializer, Post $post, string $name): ?Relationship
    {
        try {
            if ($name !== 'upvotes' && $name !== 'downvotes') {
                return null;
            }

            $actor = $serializer->getActor();
            $discussion = $post->discussion;

            $canSeeVoters = $actor->can('canSeeVoters', $discussion)
                && $actor->can('canSeeVoters', $post);

            if (! $canSeeVoters) {
                return null;
            }

            return $serializer->hasMany($post, BasicUserSerializer::class, $name);
        } catch (Throwable $e) {
            return null;
        }
    }
}
