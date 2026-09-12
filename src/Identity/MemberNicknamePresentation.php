<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\User;

/**
 * Rendering / mention contract for tech_#N display names.
 *
 * Mentions and routing stay on the immutable username (tech_<8hex>).
 * Public nicknames are escaped text, never markdown or fragment syntax.
 */
final class MemberNicknamePresentation
{
    public static function mentionIdentifier(User $user): string
    {
        return (string) $user->username;
    }

    public static function htmlText(string $nickname): string
    {
        return htmlspecialchars($nickname, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function isSafeDisplayText(string $nickname): bool
    {
        if (str_contains($nickname, "\n") || str_contains($nickname, "\r")) {
            return false;
        }

        $escaped = self::htmlText($nickname);

        return ! str_contains($escaped, '<') && ! str_contains($escaped, '>');
    }
}
