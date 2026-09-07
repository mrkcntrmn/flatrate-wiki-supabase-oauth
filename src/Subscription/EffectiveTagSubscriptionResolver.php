<?php

namespace FlatRate\SupabaseOAuth\Subscription;

/**
 * Central precedence for Follow Tags effective subscription state.
 *
 * NULL_DIRECT_SUBSCRIPTION_POLICY=FALL_BACK_TO_FAMILY_ROOT
 * Non-null direct child state always wins over the family root.
 */
final class EffectiveTagSubscriptionResolver
{
    public function __construct(private TagFamilyRegistry $registry)
    {
    }

    public function resolve(
        string $tagSlug,
        ?string $directSubscription,
        ?string $familyRootSubscription
    ): ?string {
        $direct = $this->normalize($directSubscription);

        if ($this->registry->isFamilyRoot($tagSlug)) {
            return $direct;
        }

        if ($this->registry->isFamilyChild($tagSlug)) {
            if ($direct !== null) {
                return $direct;
            }

            return $this->normalize($familyRootSubscription);
        }

        return $direct;
    }

    /**
     * FoF maps not_follow to null; treat blank/"not_follow" as null.
     */
    public function normalize(?string $subscription): ?string
    {
        if ($subscription === null) {
            return null;
        }

        $subscription = trim($subscription);
        if ($subscription === '' || $subscription === 'not_follow') {
            return null;
        }

        return $subscription;
    }
}
