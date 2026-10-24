<?php

use Illuminate\Database\Schema\Builder;

/**
 * Flarum 1 extensions added NOT NULL counters that Flarum 2 core does not
 * populate. Existing values stay in place. New core inserts receive zero.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $prefix = $db->getTablePrefix();
        $changes = [
            'users' => [
                'unread_messages' => 'int(11) NOT NULL DEFAULT 0',
                'votes' => 'int(11) NOT NULL DEFAULT 0',
            ],
            'discussions' => [
                'votes' => 'int(11) NOT NULL DEFAULT 0',
                'hotness' => 'double(10,4) NOT NULL DEFAULT 0',
            ],
        ];

        foreach ($changes as $table => $columns) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $definition) {
                if (! $schema->hasColumn($table, $column)) {
                    continue;
                }

                $db->statement(sprintf(
                    'ALTER TABLE `%s%s` MODIFY `%s` %s',
                    str_replace('`', '', $prefix),
                    str_replace('`', '', $table),
                    str_replace('`', '', $column),
                    $definition
                ));
            }
        }
    },

    'down' => function () {
        // The defaults are required by Flarum 2 inserts. Removing them does
        // not recover a historical value, so there is nothing to reverse.
    },
];
