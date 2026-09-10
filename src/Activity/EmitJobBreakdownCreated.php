<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Post\Event\Saving as PostSaving;

final class EmitJobBreakdownCreated
{
    public function __construct(
        private ActivityEmitter $emitter,
        private BrandContext $brands
    ) {
    }

    /**
     * Called after flatrate_post_markers successfully reflects marker=true.
     */
    public function emitAfterMarker(PostSaving $event, bool $enabled): void
    {
        if (! $enabled) {
            return;
        }

        $post = $event->post;
        $actor = $event->actor;
        if (! $post || ! $actor || ! $post->id) {
            return;
        }

        $brand = $this->brands->brandSlugFromPost($post);
        $obs = [
            'occurred_at' => gmdate('c'),
            'discussion_ref' => (string) ($post->discussion_id ?? ''),
            'post_ref' => (string) $post->id,
            'marker_scope' => 'reply',
            'created_by_starter' => false,
        ];
        if ($brand) {
            $obs['brand_slug'] = $brand;
        }

        $this->emitter->emit($actor, 'job_breakdown_created', $obs);
    }
}
