<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('fof_masquerade_fields')) {
            return;
        }

        if (! $schema->hasColumn('fof_masquerade_fields', 'validation')) {
            return;
        }

        $schema->table('fof_masquerade_fields', function (Blueprint $table) {
            $table->text('validation')->nullable()->change();
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('fof_masquerade_fields')) {
            return;
        }

        if (! $schema->hasColumn('fof_masquerade_fields', 'validation')) {
            return;
        }

        $schema->table('fof_masquerade_fields', function (Blueprint $table) {
            $table->string('validation')->nullable()->change();
        });
    },
];
