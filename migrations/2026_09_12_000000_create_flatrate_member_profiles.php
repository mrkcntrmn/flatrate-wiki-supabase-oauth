<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->statement('CREATE TABLE IF NOT EXISTS flatrate_member_profiles (
            user_id INT UNSIGNED NOT NULL,
            member_number INT UNSIGNED NOT NULL,
            display_mode VARCHAR(32) NOT NULL,
            custom_nickname VARCHAR(255) NULL,
            custom_nickname_origin VARCHAR(32) NULL,
            assigned_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            UNIQUE KEY flatrate_member_profiles_member_number_unique (member_number),
            CONSTRAINT flatrate_member_profiles_user_id_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT flatrate_member_profiles_member_number_positive
                CHECK (member_number > 0),
            CONSTRAINT flatrate_member_profiles_display_mode_chk
                CHECK (display_mode IN (\'member_number\', \'custom\')),
            CONSTRAINT flatrate_member_profiles_origin_chk
                CHECK (custom_nickname_origin IN (\'grandfathered\', \'user\') OR custom_nickname_origin IS NULL)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    },
    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        $db->statement('DROP TABLE IF EXISTS flatrate_member_profiles');
    },
];
