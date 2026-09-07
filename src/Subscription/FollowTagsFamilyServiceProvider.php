<?php

namespace FlatRate\SupabaseOAuth\Subscription;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * Registers family-aware NotificationSyncer binding when FoF Follow Tags is enabled.
 *
 * Flarum 1.8.19 NotificationServiceProvider does not bind NotificationSyncer::class,
 * so this companion binding is safe and resolves FamilyAwareNotificationSyncer.
 */
class FollowTagsFamilyServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(TagFamilyRegistry::class, function () {
            return new TagFamilyRegistry();
        });

        $this->container->singleton(EffectiveTagSubscriptionResolver::class, function (Container $container) {
            return new EffectiveTagSubscriptionResolver(
                $container->make(TagFamilyRegistry::class)
            );
        });

        $this->container->singleton(FollowTagsFamilyRecipientEvaluator::class, function (Container $container) {
            return new FollowTagsFamilyRecipientEvaluator(
                $container->make(TagFamilyRegistry::class),
                $container->make(EffectiveTagSubscriptionResolver::class)
            );
        });

        $this->container->singleton(FamilyUserLookup::class, function () {
            return new FamilyUserLookup();
        });

        $this->container->singleton(FollowTagsFamilyRecipientResolver::class, function (Container $container) {
            return new FollowTagsFamilyRecipientResolver(
                $container->make(TagFamilyRegistry::class),
                $container->make(EffectiveTagSubscriptionResolver::class),
                $container->make(FollowTagsFamilyRecipientEvaluator::class),
                $container->make(ConnectionInterface::class),
                $container->make(FamilyUserLookup::class)
            );
        });

        $this->container->singleton(FilterInheritedIgnoredTagMentions::class, function (Container $container) {
            return new FilterInheritedIgnoredTagMentions(
                $container->make(TagFamilyRegistry::class),
                $container->make(EffectiveTagSubscriptionResolver::class),
                $container->make(FollowTagsFamilyRecipientEvaluator::class),
                $container->make(ConnectionInterface::class)
            );
        });

        $this->container->bind(NotificationSyncer::class, function (Container $container) {
            return new FamilyAwareNotificationSyncer(
                $container->make(FollowTagsFamilyRecipientResolver::class)
            );
        });
    }
}
