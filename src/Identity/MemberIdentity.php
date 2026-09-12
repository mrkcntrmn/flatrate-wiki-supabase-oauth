<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\User;
use InvalidArgumentException;

/**
 * Permanent Community member identity: tech_#<Flarum users.id>.
 *
 * Distinct from historical NeutralIdentity::nickname() / tech_<N>.
 * Do not overload TechNumber — that class remains R3 rollback compatibility.
 */
final class MemberIdentity
{
    public const DISPLAY_MODE_MEMBER_NUMBER = 'member_number';
    public const DISPLAY_MODE_CUSTOM = 'custom';

    public const ORIGIN_GRANDFATHERED = 'grandfathered';
    public const ORIGIN_USER = 'user';

    public static function nickname(int $memberNumber): string
    {
        if ($memberNumber < 1) {
            throw new InvalidArgumentException('member_number_invalid');
        }

        return 'tech_#'.$memberNumber;
    }

    public static function memberNumber(User $user): int
    {
        $id = (int) $user->id;
        if ($id < 1) {
            throw new InvalidArgumentException('member_number_unavailable');
        }

        return $id;
    }

    public static function parseMemberNumber(mixed $nickname): ?int
    {
        if (! is_string($nickname)) {
            return null;
        }

        $value = trim($nickname);
        if ($value === '' || ! preg_match('/^tech_#([0-9]+)$/i', $value, $matches)) {
            return null;
        }

        $number = (int) $matches[1];

        return $number > 0 ? $number : null;
    }
}
