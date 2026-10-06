<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('flatrate_beta_tester_access')) {
            return;
        }

        $schema->create('flatrate_beta_tester_access', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->boolean('active')->default(false);
            $table->timestamp('synced_at');

            $table->primary('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('flatrate_beta_tester_access');
    },
];
