<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

final class CustomNicknameValidator
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function assertAcceptable(string $nickname, int $userId): void
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            throw new MemberDisplayException('custom_nickname_required');
        }

        if (ReservedTechNickname::matches($nickname)) {
            throw new MemberDisplayException('reserved_tech_nickname');
        }

        $min = max(1, (int) $this->settings->get('flarum-nicknames.min', 1));
        $max = max($min, (int) $this->settings->get('flarum-nicknames.max', 150));
        $length = mb_strlen($nickname);
        if ($length < $min || $length > $max) {
            throw new MemberDisplayException('invalid_nickname_length');
        }

        $regex = trim((string) $this->settings->get('flarum-nicknames.regex', ''));
        if ($regex !== '' && ! @preg_match('/'.$regex.'/', $nickname)) {
            throw new MemberDisplayException('invalid_nickname');
        }

        $occupied = User::query()
            ->whereRaw('LOWER(nickname) = ?', [strtolower($nickname)])
            ->where('id', '!=', $userId)
            ->exists();

        if ($occupied) {
            throw new MemberDisplayException('nickname_taken');
        }
    }
}
