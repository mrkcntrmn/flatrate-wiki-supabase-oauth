<?php

namespace FlatRate\SupabaseOAuth\Identity;

final class NeutralIdentity
{
    public static function handle(string $sub): string
    {
        return 'tech_'.substr(hash('sha256', $sub), 0, 8);
    }

    public static function nickname(string $sub): string
    {
        return 'Tech '.strtoupper(substr(hash('sha256', $sub), 0, 4));
    }
}
