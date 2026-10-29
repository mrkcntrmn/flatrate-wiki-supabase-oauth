<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => static function (Builder $schema): void {
        // Retain historical ballots when upgrading from the retired provider.
        if ($schema->hasTable('post_votes')) return;
        $schema->create('post_votes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('user_id');
            $table->integer('value')->default(0);
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });
    },
    // Ballots are product data. Extension rollback must not delete them.
    'down' => static function (Builder $schema): void {},
];
