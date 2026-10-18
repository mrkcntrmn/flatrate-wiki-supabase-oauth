<?php

namespace FlatRate\SupabaseOAuth\Brand;

use Flarum\User\User;

/**
 * Read-only, fail-closed query facade for the current Flarum actor.
 *
 * The separate signed sync endpoint updates the projection. Stale rows cannot
 * keep a revoked/offline grant active indefinitely. Current policy requires
 * a root-originated signed refresh every 3 minutes or less.
 */
final class BrandModeratorProjectionStore implements BrandModeratorProjection
{
    public function __construct(private BrandModeratorProjectionTable $rows)
    {
    }

    public function isActiveForBrand(User $actor, string $brandKey, string $capability): bool
    {
        if (!$actor->id || $capability !== 'moderate_live') {
            return false;
        }
        if (method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            return false;
        }

        return $this->rows->isActiveId((int) $actor->id, $brandKey);
    }

    /** Called only by the HMAC-authenticated root-server sync controller. */
    public function sync(User $actor, string $brandKey, bool $active, int $revision): bool
    {
        $userId = (int) $actor->id;
        if ($userId < 1) {
            return false;
        }

        return $this->rows->syncId($userId, $brandKey, $active, $revision);
    }
}
