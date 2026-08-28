<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('flatrate_sso_tickets')) {
            $schema->create('flatrate_sso_tickets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('ticket_hash', 64)->unique();
                $table->unsignedInteger('user_id')->index();
                $table->string('return_to', 2048);
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable()->index();
                $table->timestamp('created_at');

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }

        if (! $schema->hasTable('flatrate_sso_nonces')) {
            $schema->create('flatrate_sso_nonces', function (Blueprint $table) {
                $table->char('nonce_hash', 64)->primary();
                $table->timestamp('expires_at')->index();
                $table->timestamp('created_at');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('flatrate_sso_nonces');
        $schema->dropIfExists('flatrate_sso_tickets');
    },
];
