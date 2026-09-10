<?php

namespace FlatRate\SupabaseOAuth\Activity;

class EmitJobBreakdownCreated
{
    public function __construct(
        private ActivityEmitter $emitter,
        private BrandContext $brands
    ) {
    }

    /**
     * Called after flatrate_post_markers successfully reflects marker=true.
     *
     * @param object $event Flarum Post Saving event (post + actor).
     */
    public function emitAfterMarker(object $event, bool $enabled): void
    {
        if (! $enabled) {
            return;
        }

        $post = $event->post;
        $actor = $event->actor;
        if (! $post || ! $actor || ! $post->id) {
            return;
        }

        $discussion = $post->discussion ?? null;
        $createdByStarter =
            $discussion
            && (int) ($discussion->user_id ?? 0) > 0
            && (int) $discussion->user_id === (int) $actor->id;

        $brand = $this->brands->brandSlugFromPost($post);
        $obs = [
            'occurred_at' => gmdate('c'),
            'discussion_ref' => (string) ($post->discussion_id ?? ''),
            'post_ref' => (string) $post->id,
            'marker_scope' => 'post',
            'created_by_starter' => $createdByStarter,
        ];
        if ($brand) {
            $obs['brand_slug'] = $brand;
        }

        $this->emitter->emit($actor, 'job_breakdown_created', $obs);
    }
}
