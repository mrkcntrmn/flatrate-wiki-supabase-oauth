<?php

namespace FlatRate\SupabaseOAuth\Notification;

use FlatRate\SupabaseOAuth\Identity\ForumEmailPolicy;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\Driver\EmailNotificationDriver;
use Flarum\Notification\Driver\NotificationDriverInterface;
use Flarum\User\User;

/**
 * Filters only the outbound email driver recipients.
 *
 * Browser/on-site alert drivers are untouched so placeholder-backed users still
 * receive ordinary Flarum notification records and UI alerts.
 */
final class DeliverableEmailNotificationDriver implements NotificationDriverInterface
{
    public function __construct(private EmailNotificationDriver $inner)
    {
    }

    public function send(BlueprintInterface $blueprint, array $users): void
    {
        $deliverable = array_values(array_filter(
            $users,
            static fn (User $user): bool => ForumEmailPolicy::isDeliverable((string) $user->email)
        ));

        $this->inner->send($blueprint, $deliverable);
    }

    public function registerType(string $blueprintClass, array $driversEnabledByDefault): void
    {
        $this->inner->registerType($blueprintClass, $driversEnabledByDefault);
    }
}
