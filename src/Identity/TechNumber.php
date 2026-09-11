<?php

namespace FlatRate\SupabaseOAuth\Identity;

use FlatRate\SupabaseOAuth\Sso\SsoException;

/**
 * FORUM-IDENTITY-001-R3D — strict parser for HMAC SSO payload tech_number.
 *
 * Accepts only JSON integers at or above the frozen production start.
 * Rejects digit strings, floats, bools, and nickname-shaped values.
 * Does not allocate; Flarum only consumes a trusted number from the site.
 */
final class TechNumber
{
    /** First authoritative technician number after R3A/R3C freeze. */
    public const MIN_TECH_NUMBER = 20031;

    /** JavaScript Number.MAX_SAFE_INTEGER — both Node and PHP must represent exactly. */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public static function parseRequired(array $payload): int
    {
        if (! array_key_exists('tech_number', $payload) || $payload['tech_number'] === null) {
            throw new SsoException('tech_number_required', 409);
        }

        return self::parseBoundedInteger($payload['tech_number']);
    }

    public static function parseOptional(array $payload): ?int
    {
        if (! array_key_exists('tech_number', $payload) || $payload['tech_number'] === null) {
            return null;
        }

        return self::parseBoundedInteger($payload['tech_number']);
    }

    private static function parseBoundedInteger(mixed $value): int
    {
        // Strict JSON integer semantics: PHP json_decode yields int for in-range
        // whole numbers. Reject strings, floats, bools, and objects.
        if (! is_int($value)) {
            throw new SsoException('invalid_tech_number', 400);
        }

        if ($value < self::MIN_TECH_NUMBER || $value > self::MAX_SAFE_INTEGER) {
            throw new SsoException('invalid_tech_number', 400);
        }

        return $value;
    }
}
