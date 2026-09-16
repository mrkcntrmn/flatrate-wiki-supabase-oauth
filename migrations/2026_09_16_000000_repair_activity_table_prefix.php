<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * PRODUCT-ACTIVITY-001 prefix repair.
 *
 * Historical Activity migrations used raw CREATE TABLE / information_schema
 * lookups against the literal unprefixed names. Runtime OutboxStore uses the
 * prefix-aware query builder (`$db->table('flatrate_activity_outbox')`).
 *
 * When the connection prefix is non-empty (e.g. flarum_), the ledger can show
 * those migrations as applied while the runtime table is missing.
 *
 * This forward migration creates the logical/prefixed runtime tables when
 * absent and ensures terminal_at exists. It does not drop, rename, truncate,
 * or copy rows from any legacy unprefixed physical tables.
 *
 * down is intentionally a no-op: do not drop repaired runtime tables that may
 * already hold legitimate production Activity after repair.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('flatrate_activity_outbox')) {
            $outbox = Migration::createTable(
                'flatrate_activity_outbox',
                function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->string('event_kind', 64);
                    $table->unsignedInteger('flarum_user_id');
                    $table->longText('payload_json');
                    $table->unsignedInteger('attempts')->default(0);
                    $table->dateTime('available_at');
                    $table->dateTime('created_at');
                    $table->dateTime('delivered_at')->nullable();
                    $table->dateTime('terminal_at')->nullable();
                    $table->string('last_error_code', 64)->nullable();
                    $table->index(
                        ['delivered_at', 'terminal_at', 'available_at'],
                        'flatrate_activity_outbox_pending'
                    );
                }
            );
            $outbox['up']($schema);
        } elseif (! $schema->hasColumn('flatrate_activity_outbox', 'terminal_at')) {
            $schema->table('flatrate_activity_outbox', function (Blueprint $table) {
                $table->dateTime('terminal_at')->nullable();
            });
        }

        if (! $schema->hasTable('flatrate_vote_activity_state')) {
            $voteState = Migration::createTable(
                'flatrate_vote_activity_state',
                function (Blueprint $table) {
                    $table->unsignedInteger('post_id');
                    $table->unsignedInteger('user_id');
                    $table->tinyInteger('last_effective_vote')->default(0);
                    $table->unsignedInteger('state_version')->default(0);
                    $table->dateTime('updated_at');
                    $table->primary(['post_id', 'user_id']);
                }
            );
            $voteState['up']($schema);
        }
    },
    'down' => function (Builder $schema) {
        // Forward compatibility repair. Do not drop repaired runtime tables on
        // migration rollback — they may contain legitimate production Activity.
    },
];
