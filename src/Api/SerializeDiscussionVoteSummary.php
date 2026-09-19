<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Voting\DiscussionVoteSummary;
use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Discussion\Discussion;

/**
 * Bounded discussion-level upvote aggregate. No voter identities.
 */
final class SerializeDiscussionVoteSummary
{
    public function __construct(private DiscussionVoteSummary $summary)
    {
    }

    public function __invoke(AbstractSerializer $serializer, $model, array $attributes): array
    {
        if (! $model instanceof Discussion) {
            return $attributes;
        }

        $actor = $serializer->getActor();
        $payload = $this->summary->forDiscussion($model, $actor);

        $attributes['flatRateDiscussionUpvotes'] = $payload['flatRateDiscussionUpvotes'];
        $attributes['flatRateDiscussionViewerUpvoted'] = $payload['flatRateDiscussionViewerUpvoted'];
        $attributes['flatRateDiscussionViewerVotePostId'] = $payload['flatRateDiscussionViewerVotePostId'];
        $attributes['flatRateDiscussionCanUpvote'] = $payload['flatRateDiscussionCanUpvote'];

        return $attributes;
    }
}
