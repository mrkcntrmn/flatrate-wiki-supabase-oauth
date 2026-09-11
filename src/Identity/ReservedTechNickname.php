<?php

namespace FlatRate\SupabaseOAuth\Identity;

/**
 * System-controlled numeric technician nickname namespace.
 *
 * Pattern (case-insensitive): tech_<decimal digits>
 * Canonical generation remains NeutralIdentity::nickname() → lowercase tech_<N>.
 *
 * Human-controlled nickname claims matching this namespace are rejected.
 * FlatRate RegistrationToken system nicknames are applied onto the User model
 * without placing nickname in request attributes, so reservation listeners that
 * only inspect attributes.nickname do not block SSO registration.
 */
final class ReservedTechNickname
{
    public const PATTERN = '/^tech_[0-9]+$/i';

    public static function matches(mixed $nickname): bool
    {
        if (! is_string($nickname)) {
            return false;
        }

        $value = trim($nickname);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match(self::PATTERN, $value);
    }
}
