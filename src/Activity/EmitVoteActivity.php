<?php

namespace FlatRate\SupabaseOAuth\Activity;

/**
 * Listens to FoF\Gamification\Events\PostWasVoted when the class exists.
 * Registered dynamically so FoF Gamification is not a hard composer dependency.
 */
final class EmitVoteActivity
{
    public function __construct(
        private ActivityEmitter $emitter,
        private VoteStateStore $voteState,
        private BrandContext $brands
    ) {
    }

    public function handle(object $event): void
    {
        if (! isset($event->vote)) {
            return;
        }

        $vote = $event->vote;
        $actor = $vote->user ?? null;
        $post = $vote->post ?? null;
        if (! $actor || ! $post) {
            return;
        }

        $newValue = (int) $vote->value;
        $wasRecentlyCreated = (bool) ($vote->wasRecentlyCreated ?? false);

        $transition = $this->voteState->observe(
            $actor,
            (int) $post->id,
            $newValue,
            $wasRecentlyCreated
        );
        if ($transition === null) {
            return;
        }

        $brand = $this->brands->brandSlugFromPost($post);
        $obs = [
            'occurred_at' => gmdate('c'),
            'post_ref' => (string) $post->id,
            'from_value' => $transition['from_value'],
            'to_value' => $transition['to_value'],
            'vote_state_version' => $transition['vote_state_version'],
        ];
        if ($brand) {
            $obs['brand_slug'] = $brand;
        }

        $this->emitter->emit($actor, 'vote_transition', $obs);
    }
}
