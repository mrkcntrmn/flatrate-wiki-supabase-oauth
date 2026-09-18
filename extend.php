<?php

namespace FlatRate\SupabaseOAuth;

use FlatRate\SupabaseOAuth\Subscription\FilterInheritedIgnoredTagMentions;
use FlatRate\SupabaseOAuth\Subscription\FollowTagsFamilyServiceProvider;
use FlatRate\SupabaseOAuth\Voting\GlobalVotingPolicy;
use FlatRate\SupabaseOAuth\Voting\PostVotePolicy;
use FlatRate\SupabaseOAuth\Voting\VotingReadinessController;
use FlatRate\SupabaseOAuth\Voting\VotingServiceProvider;
use Flarum\Api\Serializer\BasicDiscussionSerializer;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Discussion\Event\Started;
use Flarum\Extend;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Saving;
use Flarum\Post\Post;
use Flarum\User\Event\RegisteringFromProvider;
use Flarum\User\Event\Saving as UserSaving;
use FoF\OAuth\Extend as OAuthExtend;

return [
    // Flarum 1.8 Frontend::js() stores one scalar path (overwrite).
    // Register each forum JS file through its own Frontend extender so all
    // unconditional sources reach the compiled forum asset in load order.
    // Legacy desktop IndexPage navigation is a conditional source.
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/resources/less/forum.less')
        ->css(__DIR__.'/resources/less/mobile-brand-drawer.less')
        ->js(__DIR__.'/js/dist/forum-navigation.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),

    // Flarum 1.8.19 Conditional::whenExtensionDisabled(). Only the legacy
    // IndexPage.sidebarItems renderer is gated. The shared
    // FlatRateForumNavigation contract and mobile drawer stay loaded.
    (new Extend\Conditional())
        ->whenExtensionDisabled(
            'flatrate-forum-navigation',
            [
                (new Extend\Frontend('forum'))
                    ->js(__DIR__.'/js/dist/forum-desktop-navigation.js'),
            ]
        ),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/mobile-brand-drawer.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/member-display.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/member-dashboard.js'),

    // GROWTH-001B: plain-voting UI suppression + gate-aware vote chrome.
    // Separate Frontend extender required (Flarum 1.8 js() overwrites).
    // Must end with module.exports = {} (webpack CJS entry contract).
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/plain-voting.js'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\View())
        ->extendNamespace('flarum-subscriptions', __DIR__.'/views/flarum-subscriptions')
        ->extendNamespace('flarum-mentions', __DIR__.'/views/flarum-mentions')
        ->extendNamespace('fof-follow-tags', __DIR__.'/views/fof-follow-tags'),

    (new Extend\ServiceProvider())
        ->register(ServiceProvider::class),

    (new Extend\ServiceProvider())
        ->register(Activity\ActivityServiceProvider::class),

    (new Extend\ServiceProvider())
        ->register(VotingServiceProvider::class),

    // Fail-closed rankings denial for ordinary users regardless of provider
    // migration defaults. Admins may still inspect.
    (new Extend\Policy())
        ->globalPolicy(GlobalVotingPolicy::class),

    // Additional Post::vote policy only when FoF Gamification is enabled.
    // Soft dependency: no hard require of fof/gamification in composer.json.
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-gamification', [
            (new Extend\Policy())
                ->modelPolicy(Post::class, PostVotePolicy::class),
        ]),

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
        ->listen(UserSaving::class, Listeners\RejectReservedTechNickname::class)
        ->listen(UserSaving::class, Listeners\SyncMemberDisplayFromNickname::class)
        ->listen(Saving::class, Markers\SaveJobBreakdownMarker::class)
        ->listen(Deleted::class, Markers\DeletePostMarkers::class)
        ->listen(Started::class, Activity\EmitDiscussionCreated::class)
        ->listen(Posted::class, Activity\EmitReplyCreated::class),

    (new Extend\ApiSerializer(PostSerializer::class))
        ->attribute('flatRateJobBreakdown', Api\SerializePostJobBreakdownMarker::class),

    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(Api\SerializeMemberProfile::class),

    // Guest-only public identity projection (avatar + displayName defense-in-depth).
    // Registered on BasicUserSerializer so post/like/mention includes are covered.
    (new Extend\ApiSerializer(BasicUserSerializer::class))
        ->attributes(Api\SerializeGuestPublicIdentity::class),

    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(Api\SerializeFlatRateVotingEnabled::class),

    // GROWTH-001UI: whole-discussion upvote aggregate (not FoF first-post votes).
    (new Extend\ApiSerializer(BasicDiscussionSerializer::class))
        ->attributes(Api\SerializeDiscussionVoteSummary::class),

    (new Extend\Settings())
        ->default('flatrate-activity.emit_enabled', false)
        ->default('flatrate-activity.ingest_url', '')
        ->default('flatrate-activity.drain_token_sha256', '')
        // GROWTH-001B: FlatRate vote gate. Default CLOSED.
        ->default('flatrate-voting.enabled', false),

    // Safe FoF setting defaults only while the provider is absent/disabled.
    // Flarum Settings::default() is immutable — registering the same keys
    // while fof-gamification is enabled collides with FoF's own defaults and
    // fatals boot (blocks staged provider enablement). With FoF enabled,
    // explicit admin normalization owns these values (GROWTH-001D).
    (new Extend\Conditional())
        ->whenExtensionDisabled('fof-gamification', [
            (new Extend\Settings())
                ->default('fof-gamification.autoUpvotePosts', false)
                ->default('fof-gamification.rateLimit', true)
                ->default('fof-gamification.firstPostOnly', false)
                ->default('fof-gamification.upVotesOnly', true)
                ->default('fof-gamification.allowSelfVotes', false),
        ]),

    // Automatic outbox drain via Flarum scheduler (requires host cron:
    // * * * * * php flarum schedule:run). CLI alone is not the only retry path.
    // Primary production executor (when host cron is absent): authenticated
    // POST /api/flatrate-activity/drain invoked by an external clock
    // (e.g. Cloudflare Cron). Both paths share ActivityOutboxDrainer + claim lease.
    (new Extend\Console())
        ->command(Activity\DrainActivityOutboxCommand::class)
        ->schedule(Activity\DrainActivityOutboxCommand::class, function ($event) {
            $event->everyMinute()->withoutOverlapping();
        })
        ->command(Identity\BackfillMemberProfilesCommand::class),

    (new Extend\Routes('api'))
        ->post('/flatrate-sso/provision', 'flatrate-sso.provision', Sso\ProvisionController::class)
        ->post('/flatrate-sso/ticket', 'flatrate-sso.ticket', Sso\TicketController::class)
        ->patch('/flatrate/member-display', 'flatrate.member-display', Api\MemberDisplayController::class)
        ->post('/flatrate-activity/drain', 'flatrate.activity.drain', Activity\DrainActivityOutboxController::class)
        ->get('/flatrate-voting/readiness', 'flatrate.voting.readiness', VotingReadinessController::class),

    (new Extend\Routes('forum'))
        ->get('/auth/flatrate/session', 'flatrate-sso.session', Sso\SessionController::class),

    (new Extend\Middleware('forum'))
        ->add(Middleware\ViewerIdentityContextMiddleware::class)
        ->add(Middleware\RequireFlatRateIdentity::class),

    (new Extend\Middleware('api'))
        ->add(Middleware\ViewerIdentityContextMiddleware::class)
        ->add(Middleware\RequireFlatRateIdentity::class),
];
