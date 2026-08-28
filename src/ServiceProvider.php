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
    }
}
