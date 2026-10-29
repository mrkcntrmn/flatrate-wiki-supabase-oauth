<?php

declare(strict_types=1);

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\Post;

final class NativeVoteFields
{
    public function __construct(private NativePlainVotes $votes) {}

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('flatRateVote')->visible(false)
                ->writable(fn (Post $post, Context $context) => $this->votes->canVote($context->getActor(), $post))
                ->save(fn (Post $post, int $value, Context $context) => $this->votes->cast($context->getActor(), $post, $value)),
            Schema\Integer::make('flatRatePostUpvotes')->get(fn (Post $post) => $this->votes->count($post)),
            Schema\Boolean::make('flatRatePostViewerUpvoted')->get(fn (Post $post, Context $context) => $this->votes->mine($context->getActor(), $post)),
            Schema\Boolean::make('flatRatePostCanUpvote')->get(fn (Post $post, Context $context) => $this->votes->canVote($context->getActor(), $post)),
        ];
    }
}
