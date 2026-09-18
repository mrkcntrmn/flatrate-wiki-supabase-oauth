<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\DisplayName\DriverInterface;
use Flarum\User\User;

/**
 * Decorates the active display-name driver. Guest projection uses the pushed
 * ViewerIdentityContext — never implicit container/request guessing.
 */
final class GuestAwareDisplayNameDriver implements DriverInterface
{
    public function __construct(
        private DriverInterface $inner,
        private ViewerIdentityContext $context
    ) {
    }

    public function displayName(User $user): string
    {
        if ($this->context->isGuestProjectionActive()) {
            return (string) $user->username;
        }

        return $this->inner->displayName($user);
    }

    public function inner(): DriverInterface
    {
        return $this->inner;
    }
}
