<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * Contribution brand attribution from discussion primary accepted brand context.
 * Never uses Affiliated Brand profile fields or secondary topic tags.
 *
 * Flarum Tags: primary tags have non-null position; secondary tags have null position.
 */
class BrandContext
{
    /**
     * Accepted FlatRate brand/family primary slugs (legacy production spellings preserved).
     * Sourced from main configs/forum/boards-target.json groupId=brands.
     */
    private const ACCEPTED_BRAND_SLUGS = [
        'acura' => true,
        'alpha-romeo' => true,
        'audi' => true,
        'bentley' => true,
        'bmw' => true,
        'buick' => true,
        'cadillac' => true,
        'cdjr' => true,
        'chevrolet' => true,
        'chrysler' => true,
        'dodge' => true,
        'ferrari' => true,
        'ford' => true,
        'genisis' => true,
        'gm' => true,
        'gmc' => true,
        'honda' => true,
        'hyundai' => true,
        'infiniti' => true,
        'jaguar' => true,
        'jeep' => true,
        'kia' => true,
        'lamborghini' => true,
        'lexus' => true,
        'lincoln' => true,
        'maserati' => true,
        'mazda' => true,
        'mclaren' => true,
        'mercedes-benz' => true,
        'mini' => true,
        'mitsubishi' => true,
        'nissan' => true,
        'other-makes' => true,
        'porsche' => true,
        'ram' => true,
        'rivian' => true,
        'subaru' => true,
        'tesla' => true,
        'toyota' => true,
        'volkswagen' => true,
        'volvo' => true,
    ];

    private const FAMILY_SLUGS = [
        'gm' => true,
        'cdjr' => true,
    ];

    private const FAMILY_CHILDREN = [
        'buick' => 'gm',
        'cadillac' => 'gm',
        'chevrolet' => 'gm',
        'gmc' => 'gm',
        'chrysler' => 'cdjr',
        'dodge' => 'cdjr',
        'jeep' => 'cdjr',
        'ram' => 'cdjr',
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

            return $this->brandSlugFromPrimaryTags($tags);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param iterable<mixed> $tags Tag-like objects with slug + position.
     */
    public function brandSlugFromPrimaryTags(iterable $tags): ?string
    {
        $primaryAccepted = [];
        foreach ($tags as $tag) {
            // Secondary tags have null position — never attribute from them.
            $position = is_object($tag) ? ($tag->position ?? null) : ($tag['position'] ?? null);
            if ($position === null) {
                continue;
            }

            $rawSlug = is_object($tag) ? ($tag->slug ?? '') : ($tag['slug'] ?? '');
            $slug = strtolower((string) $rawSlug);
            if ($slug === '' || ! isset(self::ACCEPTED_BRAND_SLUGS[$slug])) {
                continue;
            }
            $primaryAccepted[] = $slug;
        }

        if ($primaryAccepted === []) {
            return null;
        }

        // Prefer leaf brand over family parent for one competitive attribution.
        foreach ($primaryAccepted as $slug) {
            if (! isset(self::FAMILY_SLUGS[$slug])) {
                return $slug;
            }
        }

        return $primaryAccepted[0];
    }

    public function brandSlugFromPost(Post $post): ?string
    {
        return $this->brandSlugFromDiscussion($post->discussion ?? null);
    }

    /** @internal test helper */
    public function isAcceptedBrandSlug(string $slug): bool
    {
        return isset(self::ACCEPTED_BRAND_SLUGS[strtolower($slug)]);
    }

    /** @internal test helper */
    public function familyOf(string $slug): ?string
    {
        $slug = strtolower($slug);
        if (isset(self::FAMILY_SLUGS[$slug])) {
            return $slug;
        }

        return self::FAMILY_CHILDREN[$slug] ?? null;
    }
}
