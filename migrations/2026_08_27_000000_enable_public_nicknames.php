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
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'user.editOwnNickname')
            ->delete();

        // Do not rewrite display-name settings on rollback. An operator may have
        // intentionally customized them after this migration ran.
    },
];
