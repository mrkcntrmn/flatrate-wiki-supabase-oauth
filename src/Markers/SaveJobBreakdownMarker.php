<?php

namespace FlatRate\SupabaseOAuth\Markers;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;

final class SaveJobBreakdownMarker
{
    public function __construct(private PostMarkerStore $markers)
    {
    }

    public function handle(Saving $event): void
    {
        [$present, $enabled] = $this->markerInput($event->data);
        if (! $present) {
            return;
        }

        $post = $event->post;
        $actor = $event->actor;

        $actor->assertRegistered();
        $actor->assertPermission($enabled !== null);
        $actor->assertPermission($post instanceof CommentPost);
        $actor->assertPermission(! $this->isDiscussionStarter($post, $event->data));

        if ($post->exists) {
            $actor->assertCan('edit', $post);
        }

        $post->afterSave(function ($savedPost) use ($actor, $enabled) {
            if ($this->isDiscussionStarter($savedPost, [])) {
                return;
            }

            $this->markers->setJobBreakdown($savedPost, $actor, $enabled);
        });
    }

    /**
     * @return array{0: bool, 1: ?bool}
     */
    private function markerInput(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        if (! is_array($attributes) || ! array_key_exists('flatRateJobBreakdown', $attributes)) {
            return [false, false];
        }

        $value = $attributes['flatRateJobBreakdown'];
        if (is_bool($value)) {
            return [true, $value];
        }

        if ($value === 1 || $value === 0) {
            return [true, (bool) $value];
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return [true, true];
            }

            if (in_array($normalized, ['0', 'false'], true)) {
                return [true, false];
            }
        }

        return [true, null];
    }

    private function isDiscussionStarter($post, array $data): bool
    {
        if (($data['type'] ?? null) === 'discussions') {
            return true;
        }

        if ((int) ($post->number ?? 0) === 1) {
            return true;
        }

        $postId = (int) ($post->id ?? 0);
        $discussion = $post->discussion ?? null;

        return $postId > 0
            && $discussion
            && (int) ($discussion->first_post_id ?? 0) === $postId;
    }
}
