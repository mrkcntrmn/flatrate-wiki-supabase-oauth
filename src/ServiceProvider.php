<?php

namespace FlatRate\SupabaseOAuth;

use FlatRate\SupabaseOAuth\Auth\AutoProvisioningResponseFactory;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Forum\Auth\ResponseFactory;

final class ServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        // FoF OAuth resolves Flarum's ResponseFactory through the container.
        // Replace it with a drop-in subclass that changes behavior only for
        // the `flatrate` provider and delegates every other provider upstream.
        $this->container->bind(ResponseFactory::class, AutoProvisioningResponseFactory::class);

        // These two routes are authenticated by their own timestamped,
        // nonce-bound HMAC. Flarum's normal API stack otherwise rejects POSTs
        // without a browser session CSRF token before our authenticator runs.
        // Exempt only the bridge route names; all other API CSRF protection
        // remains unchanged.
        $this->container->extend('flarum.http.csrfExemptPaths', function (array $routes) {
            $routes[] = 'flatrate-sso.provision';
            $routes[] = 'flatrate-sso.ticket';

            return array_values(array_unique($routes));
        });
    }
}
