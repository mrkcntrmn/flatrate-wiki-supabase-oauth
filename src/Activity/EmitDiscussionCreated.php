<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Discussion\Event\Started;

final class EmitDiscussionCreated
{
    public function __construct(
        private ActivityEmitter $emitter,
        private BrandContext $brands
    ) {
    }

    public function handle(Started $event): void
    {
        $discussion = $event->discussion;
        $actor = $event->actor;
        if (! $discussion || ! $actor) {
            return;
        }

        $brand = $this->brands->brandSlugFromDiscussion($discussion);
        $obs = [
            'occurred_at' => gmdate('c'),
            'discussion_ref' => (string) $discussion->id,
            'primary_tag_slug' => $brand ?? 'unbranded',
        ];
        if ($brand) {
            $obs['brand_slug'] = $brand;
        }

        $this->emitter->emit($actor, 'discussion_created', $obs);
    }
}
