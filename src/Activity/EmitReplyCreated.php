<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Post\Event\Posted;

final class EmitReplyCreated
{
    public function __construct(
        private ActivityEmitter $emitter,
        private BrandContext $brands
    ) {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        $actor = $event->actor ?? $post->user;
        if (! $post || ! $actor) {
            return;
        }

        $isStarter = ((int) ($post->number ?? 0) === 1);
        $brand = $this->brands->brandSlugFromPost($post);
        $obs = [
            'occurred_at' => gmdate('c'),
            'discussion_ref' => (string) ($post->discussion_id ?? ''),
            'post_ref' => (string) $post->id,
            'is_starter_reply' => $isStarter,
        ];
        if ($brand) {
            $obs['brand_slug'] = $brand;
        }

        $this->emitter->emit($actor, 'reply_created', $obs);
    }
}
