<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->statement('CREATE TABLE IF NOT EXISTS flatrate_vote_activity_state (
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            last_effective_vote TINYINT NOT NULL DEFAULT 0,
            state_version INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (post_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS flatrate_activity_outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_kind VARCHAR(64) NOT NULL,
            flarum_user_id INT UNSIGNED NOT NULL,
            payload_json LONGTEXT NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            last_error_code VARCHAR(64) NULL,
            INDEX flatrate_activity_outbox_pending (delivered_at, available_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    },
    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        $db->statement('DROP TABLE IF EXISTS flatrate_activity_outbox');
        $db->statement('DROP TABLE IF EXISTS flatrate_vote_activity_state');
    },
];
