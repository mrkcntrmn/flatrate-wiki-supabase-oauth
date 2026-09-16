<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Identity\GuestIdentityProjection;
use FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\User\User;

/**
 * Defense-in-depth guest projection for BasicUserSerializer (and children).
 * Owns guest avatar neutralization; displayName mirrors the display-name driver.
 */
final class SerializeGuestPublicIdentity
{
    public function __construct(
        private ViewerIdentityContext $context,
        private GuestIdentityProjection $projection
    ) {
    }

    public function __invoke(BasicUserSerializer $serializer, User $user, array $attributes): array
    {
        if (! $this->context->isGuestProjectionActive()) {
            return [];
        }

        return [
            'displayName' => $this->projection->publicAlias($user),
            'avatarUrl' => null,
        ];
    }
}
