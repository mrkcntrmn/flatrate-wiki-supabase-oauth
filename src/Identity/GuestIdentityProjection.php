<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\User;

/**
 * Viewer-scoped public identity decisions. Does not mutate stored user rows.
 */
final class GuestIdentityProjection
{
    public function __construct(private ViewerIdentityContext $context)
    {
    }

    public function isActive(): bool
    {
        return $this->context->isGuestProjectionActive();
    }

    /**
     * Existing Flarum username is the guest public alias (tech_<8hex> for linked users).
     */
    public function publicAlias(User $user): string
    {
        return (string) $user->username;
    }

    public function projectDisplayName(User $user, string $authenticatedDisplayName): string
    {
        if (! $this->isActive()) {
            return $authenticatedDisplayName;
        }

        return $this->publicAlias($user);
    }

    public function projectAvatarUrl(?string $avatarUrl): ?string
    {
        if (! $this->isActive()) {
            return $avatarUrl;
        }

        return null;
    }
}
