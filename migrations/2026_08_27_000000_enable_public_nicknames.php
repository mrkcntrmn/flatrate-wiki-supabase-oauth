<?php

use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        // FlatRate Wiki owns authentication through Supabase. Flarum's public
        // display identity is therefore a nickname, not the private account email.
        $settings = [
            'display_name_driver' => 'nickname',
            'flarum-nicknames.set_on_registration' => '1',
            'flarum-nicknames.random_username' => '0',
        ];

        foreach ($settings as $key => $value) {
            $db->table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value]
            );
        }

        // Members may manage their own public nickname from Settings.
        $permission = [
            'group_id' => Group::MEMBER_ID,
            'permission' => 'user.editOwnNickname',
        ];

        if ($db->table('group_permission')->where($permission)->doesntExist()) {
            $db->table('group_permission')->insert($permission);
        }

        // Repair accounts created by early validation builds that derived the
        // Flarum username from email. The OAuth provider identifier is Supabase
        // `sub`, so it gives us the same deterministic neutral handle used for
        // all new registrations without exposing any email-derived text.
        $providers = $db->table('login_providers')
            ->where('provider', 'flatrate')
            ->get();

        foreach ($providers as $provider) {
            $user = $db->table('users')->where('id', $provider->user_id)->first();
            if (! $user) {
                continue;
            }

            $suffix = substr(hash('sha256', (string) $provider->identifier), 0, 8);
            $handle = 'tech_'.$suffix;
            $nickname = 'Tech '.strtoupper(substr($suffix, 0, 4));
            $updates = [];

            $handleOwnedByAnotherUser = $db->table('users')
                ->where('username', $handle)
                ->where('id', '<>', $user->id)
                ->exists();

            if (! $handleOwnedByAnotherUser && $user->username !== $handle) {
                $updates['username'] = $handle;
            }

            if (property_exists($user, 'nickname') && trim((string) $user->nickname) === '') {
                $updates['nickname'] = $nickname;
            }

            if ($updates !== []) {
                $db->table('users')->where('id', $user->id)->update($updates);
            }
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'user.editOwnNickname')
            ->delete();

        // Do not restore email-derived usernames, nicknames, or display-name
        // settings on rollback. Reintroducing public PII would be unsafe, and an
        // operator may have intentionally customized those values after migration.
    },
];
