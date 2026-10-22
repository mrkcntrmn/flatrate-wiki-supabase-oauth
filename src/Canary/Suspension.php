<?php

namespace FlatRate\SupabaseOAuth\Canary;

use DateTimeImmutable;
use DateTimeInterface;
use Flarum\User\User;

final class Suspension
{
    public static function active(User $user): bool
    {
        $until = $user->getAttribute('suspended_until');
        if ($until === null || $until === '') {
            return false;
        }

        $moment = $until instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($until)
            : new DateTimeImmutable((string) $until);

        return $moment > new DateTimeImmutable('now');
    }
}
