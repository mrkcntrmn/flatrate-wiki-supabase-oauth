<?php

/*
 * Flarum 2.0.2 identity canary entry point.
 *
 * This branch replaces the Flarum 1 extend.php. It registers only the
 * server-to-server provisioner, the one-time ticket route, and the verified
 * email listener. Voting, analytics, activity, member display, beta
 * projection, FoF OAuth, and the forum-wide identity gate are not registered
 * and must not boot from this entry point.
 */

use FlatRate\SupabaseOAuth\Canary\CsrfServiceProvider;
use FlatRate\SupabaseOAuth\Canary\ProvisionController;
use FlatRate\SupabaseOAuth\Canary\SessionController;
use FlatRate\SupabaseOAuth\Canary\TicketController;
use FlatRate\SupabaseOAuth\Canary\TicketHeaderMiddleware;
use FlatRate\SupabaseOAuth\Listeners\TrustVerifiedSupabaseEmail;
use Flarum\Extend;
use Flarum\Http\Middleware\ReferrerPolicyHeader;
use Flarum\User\Event\RegisteringFromProvider;

return [
    (new Extend\ServiceProvider())
        ->register(CsrfServiceProvider::class),

    (new Extend\Middleware('forum'))
        ->insertBefore(ReferrerPolicyHeader::class, TicketHeaderMiddleware::class),

    (new Extend\Event())
        ->listen(RegisteringFromProvider::class, TrustVerifiedSupabaseEmail::class),

    (new Extend\Routes('api'))
        ->post('/flatrate-sso/provision', 'flatrate.sso.provision', ProvisionController::class)
        ->post('/flatrate-sso/ticket', 'flatrate.sso.ticket', TicketController::class),

    (new Extend\Routes('forum'))
        ->get('/auth/flatrate/session', 'flatrate.sso.session', SessionController::class),
];
