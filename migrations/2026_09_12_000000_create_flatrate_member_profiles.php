<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

$definition = Migration::createTable(
    'flatrate_member_profiles',
    function (Blueprint $table) {
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('member_number');
        $table->string('display_mode', 32);
        $table->string('custom_nickname', 255)->nullable();
        $table->string('custom_nickname_origin', 32)->nullable();
        $table->dateTime('assigned_at');
        $table->dateTime('updated_at');

        $table->primary('user_id');
        $table->unique('member_number');

        $table->foreign('user_id')
            ->references('id')
            ->on('users')
            ->onDelete('cascade');
    }
);

return [
    'up' => function (Builder $schema) use ($definition) {
        $definition['up']($schema);

        $connection = $schema->getConnection();
        $table = $connection->getTablePrefix().'flatrate_member_profiles';
        if (! preg_match('/^[A-Za-z0-9_]*flatrate_member_profiles$/', $table)) {
            throw new \RuntimeException('unsafe_member_profile_table_name');
        }

        // Illuminate 8 Blueprint has no check(); MariaDB 11.4 parses and enforces these.
        $connection->statement(
            'ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$table.'_member_number_positive` CHECK (member_number > 0)'
        );
        $connection->statement(
            'ALTER TABLE `'.$table."` ADD CONSTRAINT `{$table}_display_mode_chk` CHECK (display_mode IN ('member_number', 'custom'))"
        );
        $connection->statement(
            'ALTER TABLE `'.$table."` ADD CONSTRAINT `{$table}_origin_chk` CHECK (custom_nickname_origin IN ('grandfathered', 'user') OR custom_nickname_origin IS NULL)"
        );
    },
    'down' => $definition['down'],
];
