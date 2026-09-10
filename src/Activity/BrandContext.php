<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * Contribution brand attribution from discussion primary tags.
 * Does not use Affiliated Brand profile fields.
 */
final class BrandContext
{
    private const UNBRANDED = [
        'start-here',
        'general-shop-discussion',
        'job-breakdown',
    ];

    public function brandSlugFromDiscussion(?Discussion $discussion): ?string
    {
        if (! $discussion) {
            return null;
        }

        try {
            $tags = $discussion->tags ?? null;
            if (! $tags) {
                return null;
            }
            foreach ($tags as $tag) {
                $slug = strtolower((string) ($tag->slug ?? ''));
                if ($slug === '' || in_array($slug, self::UNBRANDED, true)) {
                    continue;
                }
                // Prefer leaf/brand-looking slugs.
                if (preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
                    return $slug;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function brandSlugFromPost(Post $post): ?string
    {
        return $this->brandSlugFromDiscussion($post->discussion ?? null);
    }
}
