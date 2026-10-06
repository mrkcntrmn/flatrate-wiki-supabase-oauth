<?php

namespace FlatRate\SupabaseOAuth\Sso;

/**
 * Strict JSON boolean for beta_tester_active.
 * Absent is a distinct staged-rollout signal. Strings and numbers are rejected.
 */
final class BetaTesterPayload
{
    public static function optional(array $body): ?bool
    {
        if (! array_key_exists('beta_tester_active', $body)) {
            return null;
        }

        return self::strict($body['beta_tester_active']);
    }

    public static function required(array $body): bool
    {
        if (! array_key_exists('beta_tester_active', $body)) {
            throw new SsoException('invalid_beta_tester_active', 400);
        }

        return self::strict($body['beta_tester_active']);
    }

    private static function strict(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new SsoException('invalid_beta_tester_active', 400);
        }

        return $value;
    }
}
