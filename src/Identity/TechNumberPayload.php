<?php

namespace FlatRate\SupabaseOAuth\Identity;

use FlatRate\SupabaseOAuth\Sso\SsoException;

/**
 * FORUM-IDENTITY-001-R3D — bounded parser for HMAC SSO payload tech_number.
 *
 * Accepts only JSON integers in the JavaScript-safe positive range.
 * Does not accept "tech_20031", floats, bools, or digit strings.
 */
final class TechNumberPayload
{
    /** JavaScript Number.MAX_SAFE_INTEGER — both Node and PHP must represent exactly. */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public static function parseRequired(array $payload): int
    {
        if (! array_key_exists('tech_number', $payload) || $payload['tech_number'] === null) {
            throw new SsoException('forum_tech_number_required', 409);
        }

        return self::parsePositiveInteger($payload['tech_number']);
    }

    public static function parseOptional(array $payload): ?int
    {
        if (! array_key_exists('tech_number', $payload) || $payload['tech_number'] === null) {
            return null;
        }

        return self::parsePositiveInteger($payload['tech_number']);
    }

    private static function parsePositiveInteger(mixed $value): int
    {
        // Strict JSON integer semantics: PHP json_decode yields int for in-range
        // whole numbers. Reject strings, floats, bools, and objects.
        if (! is_int($value)) {
            throw new SsoException('invalid_tech_number', 400);
        }

        if ($value < 1 || $value > self::MAX_SAFE_INTEGER) {
            throw new SsoException('invalid_tech_number', 400);
        }

        return $value;
    }
}
