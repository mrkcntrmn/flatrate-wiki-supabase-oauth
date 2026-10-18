<?php

namespace FlatRate\SupabaseOAuth\Brand;

use Flarum\User\User;

/**
 * Trusted server-side Brand moderator projection.
 *
 * Live Chat's scoped moderator gate may resolve this interface; it never
 * accepts a browser or invitation token as evidence of a grant.
 */
interface BrandModeratorProjection
{
    public function isActiveForBrand(User $actor, string $brandKey, string $capability): bool;
}
