<?php

namespace Flarum\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Slim Flarum 1.8.19 Migration::createTable factory for disposable tests.
 * Upstream: flarum/core src/Database/Migration.php
 */
abstract class Migration
{
    public static function createTable($name, callable $definition)
    {
        return [
            'up' => function (Builder $schema) use ($name, $definition) {
                $schema->create($name, function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            },
            'down' => function (Builder $schema) use ($name) {
                $schema->drop($name);
            },
        ];
    }
}
