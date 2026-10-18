<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Flarum-side projection only. Authoritative approvals live in the root app.
 * Applying this Flarum migration to production requires separate permission.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('flatrate_brand_moderator_access')) {
            return;
        }

        $schema->create('flatrate_brand_moderator_access', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->string('brand_key', 64);
            $table->boolean('active')->default(false);
            $table->unsignedBigInteger('revision');
            $table->timestamp('synced_at');

            $table->primary(['user_id', 'brand_key'], 'flatrate_brand_mod_user_brand_pk');
            $table->foreign('user_id', 'flatrate_brand_mod_user_fk')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('flatrate_brand_moderator_access');
    },
];
