<?php

namespace FlatRate\SupabaseOAuth;

use Flarum\Extend;
use Flarum\User\Event\RegisteringFromProvider;
use FoF\OAuth\Extend as OAuthExtend;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/resources/less/forum.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(ServiceProvider::class),

    new OAuthExtend\RegisterProvider(Providers\FlatRate::class),

    (new Extend\Event())
        ->listen(RegisteringFromProvider::class, Listeners\TrustVerifiedSupabaseEmail::class),

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
