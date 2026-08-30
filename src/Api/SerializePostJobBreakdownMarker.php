<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Markers\PostMarkerStore;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;

final class SerializePostJobBreakdownMarker
{
    public function __construct(private PostMarkerStore $markers)
    {
    }

    public function __invoke(PostSerializer $serializer, Post $post, array $attributes): bool
    {
        if (! $post instanceof CommentPost || ! $post->exists || ! $post->id) {
            return false;
        }

        return $this->markers->hasJobBreakdown((int) $post->id);
    }
}
