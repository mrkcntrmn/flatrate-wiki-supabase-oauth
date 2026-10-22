<?php

namespace FlatRate\SupabaseOAuth\Canary;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * The provision and ticket routes authenticate with HMAC. Flarum otherwise
 * rejects those POSTs for lack of a browser CSRF token before that check runs.
 * No other route is exempted.
 */
final class CsrfServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend('flarum.http.csrfExemptPaths', function (array $routes) {
            $routes[] = 'flatrate.sso.provision';
            $routes[] = 'flatrate.sso.ticket';

            return array_values(array_unique($routes));
        });
    }
}
