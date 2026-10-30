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
     * Keep linked neutral usernames; never expose a legacy/custom username as an alias.
     */
    public function publicAlias(User $user): string
    {
        $username = (string) $user->username;
        if (preg_match('/^tech_[0-9a-f]{8}$/D', $username) === 1) {
            return $username;
        }

        // The Flarum row id is already public in JSON:API. Domain separation
        // avoids deriving this fallback from a nickname, email or external sub.
        // This is presentation only: no stored username or mapping changes.
        return (int) $user->id > 0
            ? NeutralIdentity::handle('flatrate:legacy-public-user:'.(int) $user->id)
            : 'tech_00000000';
    }

    public function publicSlug(User $user): string
    {
        $alias = $this->publicAlias($user);

        // Existing neutral routes stay byte-identical. A row-id prefix lets a
        // legacy profile resolve without an alias table or a full-table scan.
        return $alias === (string) $user->username ? $alias : (int) $user->id.'-'.$alias;
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
