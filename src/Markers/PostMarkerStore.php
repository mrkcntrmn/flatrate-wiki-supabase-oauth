<?php

namespace FlatRate\SupabaseOAuth\Markers;

use Flarum\Post\Post;
use Flarum\User\User;

final class PostMarkerStore
{
    public const JOB_BREAKDOWN = 'job-breakdown';

    public function hasJobBreakdown(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return (new Post())->getConnection()
            ->table('flatrate_post_markers')
            ->where('post_id', $postId)
            ->where('marker_key', self::JOB_BREAKDOWN)
            ->exists();
    }

    public function setJobBreakdown(Post $post, User $actor, bool $enabled): void
    {
        $postId = (int) $post->id;
        if ($postId <= 0) {
            return;
        }

        $db = $post->getConnection();

        if (! $enabled) {
            $db->table('flatrate_post_markers')
                ->where('post_id', $postId)
                ->where('marker_key', self::JOB_BREAKDOWN)
                ->delete();

            return;
        }

        $db->table('flatrate_post_markers')->updateOrInsert(
            [
                'post_id' => $postId,
                'marker_key' => self::JOB_BREAKDOWN,
            ],
            [
                'created_by' => $actor->id ?: null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public function deleteForPost(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        (new Post())->getConnection()
            ->table('flatrate_post_markers')
            ->where('post_id', $postId)
            ->delete();
    }
}
