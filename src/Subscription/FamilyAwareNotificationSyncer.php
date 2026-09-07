<?php

namespace FlatRate\SupabaseOAuth\Subscription;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\NotificationSyncer;

/**
 * Resolves FoF Follow Tags family recipients before parent sync reconciliation.
 *
 * Family recipients are resolved here and passed into parent::sync().
 * They are never injected through NotificationSyncer callbacks.
 */
class FamilyAwareNotificationSyncer extends NotificationSyncer
{
    public function __construct(private FollowTagsFamilyRecipientResolver $resolver)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function sync(BlueprintInterface $blueprint, array $users)
    {
        $resolved = $this->resolver->resolve($blueprint, $users);

        $this->syncWithParent($blueprint, $resolved);
    }

    /**
     * Seam for runtime tests to observe the final recipient array without
     * requiring Flarum notification table writes.
     *
     * @param \Flarum\User\User[] $users
     */
    protected function syncWithParent(BlueprintInterface $blueprint, array $users): void
    {
        parent::sync($blueprint, $users);
    }
}
