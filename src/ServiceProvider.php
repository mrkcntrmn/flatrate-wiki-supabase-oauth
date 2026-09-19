<?php

namespace FlatRate\SupabaseOAuth;

use FlatRate\SupabaseOAuth\Auth\AutoProvisioningResponseFactory;
use FlatRate\SupabaseOAuth\Identity\GuestAwareDisplayNameDriver;
use FlatRate\SupabaseOAuth\Identity\GuestIdentityProjection;
use FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\User\DisplayName\DriverInterface;
use Flarum\User\User;

final class ServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        // FoF OAuth resolves Flarum's ResponseFactory through the container.
        // Replace it with a drop-in subclass that changes behavior only for
        // the `flatrate` provider and delegates every other provider upstream.
        $this->container->bind(ResponseFactory::class, AutoProvisioningResponseFactory::class);

        $this->container->singleton(ViewerIdentityContext::class, function () {
            return new ViewerIdentityContext();
        });

        $this->container->singleton(GuestIdentityProjection::class, function ($container) {
            return new GuestIdentityProjection($container->make(ViewerIdentityContext::class));
        });

        // Decorate the active display-name driver (usually nickname) without
        // changing display_name_driver settings or copying Flarum selection.
        $this->container->extend('flarum.user.display_name.driver', function ($driver, $container) {
            if ($driver instanceof GuestAwareDisplayNameDriver) {
                return $driver;
            }

            return new GuestAwareDisplayNameDriver(
                $driver,
                $container->make(ViewerIdentityContext::class)
            );
        });

        // These routes are authenticated by their own credentials (HMAC SSO or
        // drain bearer digest). Flarum's normal API stack otherwise rejects
        // POSTs without a browser session CSRF token before our authenticators
        // run. Exempt only those named bridge routes; all other API CSRF
        // protection remains unchanged.
        $this->container->extend('flarum.http.csrfExemptPaths', function (array $routes) {
            $routes[] = 'flatrate-sso.provision';
            $routes[] = 'flatrate-sso.ticket';
            $routes[] = 'flatrate.activity.drain';

            return array_values(array_unique($routes));
        });
    }

    public function boot()
    {
        // UserServiceProvider::boot already called setDisplayNameDriver(make()).
        // Re-bind the (possibly already decorated) resolved singleton so the
        // static User accessor always sees GuestAwareDisplayNameDriver when
        // this extension is enabled.
        $driver = $this->container->make('flarum.user.display_name.driver');
        if ($driver instanceof DriverInterface) {
            User::setDisplayNameDriver($driver);
        }
    }
}
