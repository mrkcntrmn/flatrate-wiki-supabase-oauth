<?php

/**
 * Pinned behavioral replica of flarum/nicknames v1.8.3 AddNicknameValidation.
 *
 * Production custom nicknames go through EditUser → this upstream validator.
 * This fixture exists so CI can prove the same rules without installing
 * flarum/core. Do not use it as a production validator.
 *
 * Source: https://github.com/flarum/nicknames/blob/v1.8.3/src/AddNicknameValidation.php
 */
function flarum_nicknames_1_8_3_has_forbidden_syntax(string $value): bool
{
    return (bool) preg_match('/[\[\]()<>]/', $value);
}

/**
 * @param  array<int,array{id:int,username?:string,nickname?:?string}>  $users
 */
function flarum_nicknames_1_8_3_unique_conflict(
    string $value,
    int $userId,
    array $users,
    bool $uniqueEnabled
): bool {
    if (! $uniqueEnabled) {
        return false;
    }

    $needle = strtolower($value);
    foreach ($users as $user) {
        if ((int) ($user['id'] ?? 0) === $userId) {
            continue;
        }
        if (strtolower((string) ($user['username'] ?? '')) === $needle) {
            return true;
        }
        if (strtolower((string) ($user['nickname'] ?? '')) === $needle) {
            return true;
        }
    }

    return false;
}

function flarum_nicknames_1_8_3_accepts(
    string $value,
    int $userId,
    array $users,
    bool $uniqueEnabled = true,
    int $min = 1,
    int $max = 150,
    string $regex = ''
): bool {
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length < $min || $length > $max) {
        return false;
    }
    if (flarum_nicknames_1_8_3_has_forbidden_syntax($value)) {
        return false;
    }
    if ($regex !== '' && ! preg_match_all('/'.$regex.'/', $value)) {
        return false;
    }

    return ! flarum_nicknames_1_8_3_unique_conflict($value, $userId, $users, $uniqueEnabled);
}
