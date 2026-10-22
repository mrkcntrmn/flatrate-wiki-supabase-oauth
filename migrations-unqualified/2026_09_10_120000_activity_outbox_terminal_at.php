<?php

use Illuminate\Database\Schema\Builder;

/**
 * REP-001F.A1 R1: ensure terminal_at exists for installs that already applied
 * the original outbox table without it.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $exists = $db->select(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'flatrate_activity_outbox'
               AND COLUMN_NAME = 'terminal_at'"
        );
        $count = (int) (($exists[0]->c ?? $exists[0]->C ?? 0));
        if ($count === 0) {
            $db->statement('ALTER TABLE flatrate_activity_outbox ADD COLUMN terminal_at DATETIME NULL');
        }
    },
    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        try {
            $db->statement('ALTER TABLE flatrate_activity_outbox DROP COLUMN terminal_at');
        } catch (\Throwable) {
            // ignore
        }
    },
];
