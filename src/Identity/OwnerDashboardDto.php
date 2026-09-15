<?php

namespace FlatRate\SupabaseOAuth\Identity;

/**
 * Phase 1 owner-dashboard chrome contract.
 *
 * Server-authorized, allowlisted, and never mixed into the public user
 * projection. This is not the future owner-profile bridge and contains no
 * private technician, account, Garage, DM, or Live fields.
 */
final class OwnerDashboardDto
{
    public const SCHEMA_VERSION = 1;

    public const ACCOUNT_URL = 'https://flatrate.wiki/account';

    public const SETTINGS_PATH = '/settings';

    /**
     * @var list<string>
     */
    public const SECTION_IDS = [
        'overview',
        'identity',
        'contributions',
        'account_security',
        'notifications',
    ];

    /**
     * @var list<string>
     */
    public const PUBLIC_ATTRIBUTE_KEYS = [
        'flatRateMemberNumber',
        'flatRateMemberNickname',
    ];

    /**
     * @var list<string>
     */
    public const OWNER_ATTRIBUTE_KEYS = [
        'flatRateMemberNumber',
        'flatRateMemberNickname',
        'flatRateOwnerDashboard',
        'flatRateNicknameMode',
        'flatRateCustomNickname',
        'flatRateCustomNicknameOrigin',
    ];

    /**
     * @var list<string>
     */
    public const FORBIDDEN_KEYS = [
        'zip',
        'zip_private',
        'rate',
        'rates',
        'pay',
        'email',
        'phone',
        'providers',
        'auth_providers',
        'questionnaire',
        'brand_subscriptions',
        'garage',
        'vin',
        'dm',
        'live',
        'activity_subject_id',
        'merit',
        'points',
        'following',
        'for_you',
    ];

    /**
     * @return array{
     *     schema_version: int,
     *     sections: list<array{id: string}>,
     *     account_url: string,
     *     settings_path: string
     * }
     */
    public static function make(): array
    {
        $sections = [];
        foreach (self::SECTION_IDS as $id) {
            $sections[] = ['id' => $id];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sections' => $sections,
            'account_url' => self::ACCOUNT_URL,
            'settings_path' => self::SETTINGS_PATH,
        ];
    }
}
