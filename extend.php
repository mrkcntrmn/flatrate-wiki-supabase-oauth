<?php

namespace FlatRate\SupabaseOAuth;

use FlatRate\SupabaseOAuth\Subscription\FilterInheritedIgnoredTagMentions;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyServiceProvider;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Discussion\Event\Started;
use Flarum\Extend;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Saving;
use Flarum\User\Event\RegisteringFromProvider;
use FoF\OAuth\Extend as OAuthExtend;

return [
    // Flarum 1.8 Frontend::js() stores one scalar path (overwrite).
    // Register each forum JS file through its own Frontend extender so all
    // three sources reach the compiled forum asset in load order.
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/resources/less/forum.less')
        ->css(__DIR__.'/resources/less/mobile-brand-drawer.less')
        ->js(__DIR__.'/js/dist/forum-navigation.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/mobile-brand-drawer.js'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\View())
        ->extendNamespace('flarum-subscriptions', __DIR__.'/views/flarum-subscriptions')
        ->extendNamespace('flarum-mentions', __DIR__.'/views/flarum-mentions')
        ->extendNamespace('fof-follow-tags', __DIR__.'/views/fof-follow-tags'),

    (new Extend\ServiceProvider())
        ->register(ServiceProvider::class),

    (new Extend\ServiceProvider())
        ->register(Activity\ActivityServiceProvider::class),

    // FORUM-SUB-001: GM/CDJR family notification inheritance.
    // Bound only when FoF Follow Tags is enabled. Does not hard-depend on FoF
    // classes at boot when the extension is absent/disabled.
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-follow-tags', [
            (new Extend\ServiceProvider())
                ->register(FollowTagsFamilyServiceProvider::class),

            // beforeSending may only remove recipients (inherited ignore).
            // Family recipients are added in FamilyAwareNotificationSyncer
            // before parent::sync() reconciliation — never here.
            (new Extend\Notification())
                ->beforeSending(FilterInheritedIgnoredTagMentions::class),
        ]),

    new OAuthExtend\RegisterProvider(Providers\FlatRate::class),

    // Replace only the outbound email notification driver. Do not use
    // Notification::beforeSending() for reserved-email filtering; that would
    // filter recipients for every driver (including browser/on-site alerts).
    (new Extend\Notification())
        ->driver('email', Notification\DeliverableEmailNotificationDriver::class),

    (new Extend\Event())
        ->listen(RegisteringFromProvider::class, Listeners\TrustVerifiedSupabaseEmail::class)
        ->listen(Saving::class, Markers\SaveJobBreakdownMarker::class)
        ->listen(Deleted::class, Markers\DeletePostMarkers::class)
        ->listen(Started::class, Activity\EmitDiscussionCreated::class)
        ->listen(Posted::class, Activity\EmitReplyCreated::class),

    (new Extend\ApiSerializer(PostSerializer::class))
        ->attribute('flatRateJobBreakdown', Api\SerializePostJobBreakdownMarker::class),

    (new Extend\Settings())
        ->default('flatrate-activity.emit_enabled', false)
        ->default('flatrate-activity.ingest_url', ''),

    (new Extend\Routes('api'))
        ->post('/flatrate-sso/provision', 'flatrate-sso.provision', Sso\ProvisionController::class)
        ->post('/flatrate-sso/ticket', 'flatrate-sso.ticket', Sso\TicketController::class),

    (new Extend\Routes('forum'))
        ->get('/auth/flatrate/session', 'flatrate-sso.session', Sso\SessionController::class),

    (new Extend\Middleware('forum'))
        ->add(Middleware\RequireFlatRateIdentity::class),

    (new Extend\Middleware('api'))
        ->add(Middleware\RequireFlatRateIdentity::class),
];
