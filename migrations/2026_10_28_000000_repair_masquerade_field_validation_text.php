<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('fof_masquerade_fields') || !$schema->hasColumn('fof_masquerade_fields', 'validation')) {
            return;
        }

        $connection = $schema->getConnection();
        $table = $connection->getTablePrefix().'fof_masquerade_fields';

        $connection->statement(
            "ALTER TABLE `{$table}` MODIFY COLUMN `validation` TEXT NULL"
        );
    },

    'down' => function (Builder $schema) {
        // Safe no-op: preserve TEXT column width so >255-character validation
        // rules are never truncated on rollback.
    },
];
