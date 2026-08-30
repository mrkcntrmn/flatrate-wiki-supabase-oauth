<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('flatrate_post_markers')) {
            $schema->create('flatrate_post_markers', function (Blueprint $table) {
                $table->unsignedInteger('post_id');
                $table->string('marker_key', 64);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamp('created_at');

                $table->primary(['post_id', 'marker_key']);
                $table->index('marker_key');

                $table->foreign('post_id')
                    ->references('id')
                    ->on('posts')
                    ->onDelete('cascade');

                $table->foreign('created_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('flatrate_post_markers');
    },
];
