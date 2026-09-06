<?php

namespace FlatRate\SupabaseOAuth\Identity;

/**
 * Single source of truth for FlatRate's reserved Flarum-internal email namespace.
 *
 * Addresses at @users.flatrate.wiki satisfy Flarum's unique email-shaped field
 * for phone-first Community users whose real email is still unconfirmed.
 * They are never outbound-deliverable.
 *
 * SSO payload email_verified=true does NOT imply outbound deliverability.
 */
final class ForumEmailPolicy
{
    public const INTERNAL_DOMAIN = 'users.flatrate.wiki';

    public static function isInternal(string $email): bool
    {
        $domain = self::domain($email);
        if ($domain === null) {
            return false;
        }

        return strcasecmp($domain, self::INTERNAL_DOMAIN) === 0;
    }

    public static function isDeliverable(string $email): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return ! self::isInternal($email);
    }

    /**
     * Automatic reconciliation is one-way only:
     * reserved internal placeholder -> confirmed real email.
     */
    public static function canPromote(
        string $currentEmail,
        string $incomingEmail,
        bool $incomingEmailVerified
    ): bool {
        if (! $incomingEmailVerified) {
            return false;
        }

        if (! self::isInternal($currentEmail)) {
            return false;
        }

        if (! self::isDeliverable($incomingEmail)) {
            return false;
        }

        return true;
    }

    private static function domain(string $email): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $at = strrpos($email, '@');
        if ($at === false || $at === strlen($email) - 1) {
            return null;
        }

        $domain = substr($email, $at + 1);
        if ($domain === '' || str_contains($domain, '@')) {
            return null;
        }

        return $domain;
    }
}
