<?php

namespace FlatRate\SupabaseOAuth\Presence;

/**
 * Frozen V1 US state + DC allowlist for Community presence geography.
 */
final class UsStateAllowlist
{
    /**
     * @var list<string>
     */
    public const CODES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
        'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
        'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
        'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
        'DC',
    ];

    public static function contains(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::CODES, true);
    }
}
