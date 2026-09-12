<?php

namespace FlatRate\SupabaseOAuth\Identity;

/**
 * Reserved public nickname namespaces.
 *
 * Legacy historical identities: tech_<decimal digits>
 * Canonical permanent member identities: tech_#<decimal digits>
 *
 * Human-controlled generic nickname claims matching either namespace are
 * rejected. Trusted system/member-display actions set nicknames on the User
 * model without placing nickname in request attributes.
 */
final class ReservedTechNickname
{
    public const LEGACY_PATTERN = '/^tech_[0-9]+$/i';
    public const CANONICAL_PATTERN = '/^tech_#[0-9]+$/i';

    /** Combined reservation: legacy numeric or canonical member identity. */
    public const PATTERN = '/^tech_(?:[0-9]+|#[0-9]+)$/i';

    public static function matchesLegacy(mixed $nickname): bool
    {
        return self::matchesPattern($nickname, self::LEGACY_PATTERN);
    }

    public static function matchesCanonical(mixed $nickname): bool
    {
        return self::matchesPattern($nickname, self::CANONICAL_PATTERN);
    }

    public static function matches(mixed $nickname): bool
    {
        return self::matchesLegacy($nickname) || self::matchesCanonical($nickname);
    }

    private static function matchesPattern(mixed $nickname, string $pattern): bool
    {
        if (! is_string($nickname)) {
            return false;
        }

        $value = trim($nickname);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match($pattern, $value);
    }
}
