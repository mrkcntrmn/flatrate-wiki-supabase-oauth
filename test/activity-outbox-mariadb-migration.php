<?php

/**
 * PRODUCT-ACTIVITY-001: Activity outbox MariaDB prefix regression harness.
 *
 * Runs the REAL historical Activity migrations plus the REAL forward repair
 * against MariaDB for prefix="" and prefix="flarum_".
 *
 * Does not touch production.
 */

declare(strict_types=1);

$harnessDir = __DIR__.'/harness/mariadb-migration';
$autoload = $harnessDir.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "MARIADB_HARNESS_MISSING_VENDOR: run composer install in {$harnessDir}\n");
    exit(2);
}

require $autoload;
require __DIR__.'/fixtures/flarum-1.8.19-Migration.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Builder;

$failures = 0;

function pass(string $label): void
{
    echo "[PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

function assertTrue(bool $cond, string $label, string $detail = ''): void
{
    $cond ? pass($label) : fail($label, $detail);
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected === $actual) {
        pass($label);
    } else {
        fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}

function envOr(string $key, string $default): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}

function connectCapsule(string $prefix): Capsule
{
    $config = [
        'driver' => 'mysql',
        'host' => envOr('MARIADB_HOST', '127.0.0.1'),
        'port' => envOr('MARIADB_PORT', '3306'),
        'database' => envOr('MARIADB_DATABASE', 'flarum_test'),
        'username' => envOr('MARIADB_USER', 'flarum'),
        'password' => envOr('MARIADB_PASSWORD', 'flarum'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => $prefix,
        'prefix_indexes' => true,
        'engine' => 'InnoDB',
    ];
    $socket = getenv('MARIADB_SOCKET');
    if (is_string($socket) && $socket !== '') {
        $config['unix_socket'] = $socket;
    }

    $capsule = new Capsule();
    $capsule->addConnection($config);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule;
}

function waitForMariaDb(): Capsule
{
    $last = null;
    for ($i = 0; $i < 30; $i++) {
        try {
            $capsule = connectCapsule('');
            $capsule->getConnection()->select('SELECT 1');

            return $capsule;
        } catch (Throwable $e) {
            $last = $e;
            sleep(1);
        }
    }

    fwrite(STDERR, 'MARIADB_UNAVAILABLE: '.($last ? $last->getMessage() : 'unknown')."\n");
    exit(2);
}

function physical(Builder $schema, string $name): string
{
    return $schema->getConnection()->getTablePrefix().$name;
}

function physicalExists(Builder $schema, string $physicalName): bool
{
    $rows = $schema->getConnection()->select(
        'SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$physicalName]
    );

    return $rows !== [];
}

function dropActivityLeftovers(Builder $schema): void
{
    $db = $schema->getConnection();
    foreach ([
        'flatrate_activity_outbox',
        'flarum_flatrate_activity_outbox',
        'flatrate_vote_activity_state',
        'flarum_flatrate_vote_activity_state',
    ] as $table) {
        $db->statement('DROP TABLE IF EXISTS `'.$table.'`');
    }
}

function loadMigration(string $path): array
{
    $migration = require $path;
    if (! is_array($migration) || ! isset($migration['up'], $migration['down'])) {
        throw new RuntimeException('invalid_migration '.$path);
    }

    return $migration;
}

function historicalMigrations(): array
{
    $root = dirname(__DIR__).'/migrations';

    return [
        loadMigration($root.'/2026_09_10_000000_create_activity_emitter_tables.php'),
        loadMigration($root.'/2026_09_10_120000_activity_outbox_terminal_at.php'),
    ];
}

function repairMigration(): array
{
    return loadMigration(
        dirname(__DIR__).'/migrations/2026_09_16_000000_repair_activity_table_prefix.php'
    );
}

function columnExists(Builder $schema, string $physicalTable, string $column): bool
{
    $rows = $schema->getConnection()->select(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?',
        [$physicalTable, $column]
    );

    return $rows !== [];
}

function indexExists(Builder $schema, string $physicalTable, string $indexName): bool
{
    $rows = $schema->getConnection()->select(
        'SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?',
        [$physicalTable, $indexName]
    );

    return $rows !== [];
}

function outboxInsertPayload(): array
{
    $now = '2026-09-16 00:00:00';

    return [
        'event_kind' => 'reply_created',
        'flarum_user_id' => 1,
        'payload_json' => '{"schema_version":1,"test":true}',
        'attempts' => 0,
        'available_at' => $now,
        'created_at' => $now,
        'delivered_at' => null,
        'terminal_at' => null,
        'last_error_code' => null,
    ];
}

function assertOutboxSchema(Builder $schema, string $label): void
{
    assertTrue($schema->hasTable('flatrate_activity_outbox'), "{$label} logical outbox exists");
    $physical = physical($schema, 'flatrate_activity_outbox');
    assertSame(
        $schema->getConnection()->getTablePrefix().'flatrate_activity_outbox',
        $physical,
        "{$label} outbox physical name"
    );

    foreach ([
        'id',
        'event_kind',
        'flarum_user_id',
        'payload_json',
        'attempts',
        'available_at',
        'created_at',
        'delivered_at',
        'terminal_at',
        'last_error_code',
    ] as $column) {
        assertTrue(
            $schema->hasColumn('flatrate_activity_outbox', $column),
            "{$label} outbox column {$column}"
        );
    }

    assertTrue(
        columnExists($schema, $physical, 'terminal_at'),
        "{$label} terminal_at on physical outbox"
    );

    // Explicit index name from the repair migration; Illuminate keeps the
    // provided name even when prefix_indexes is enabled.
    $indexName = 'flatrate_activity_outbox_pending';
    assertTrue(
        indexExists($schema, $physical, $indexName),
        "{$label} pending index {$indexName}"
    );
}

function assertVoteStateSchema(Builder $schema, string $label): void
{
    assertTrue($schema->hasTable('flatrate_vote_activity_state'), "{$label} logical vote-state exists");
    assertSame(
        $schema->getConnection()->getTablePrefix().'flatrate_vote_activity_state',
        physical($schema, 'flatrate_vote_activity_state'),
        "{$label} vote-state physical name"
    );
}

function runtimeOutboxInsert(Builder $schema, string $label): int
{
    $id = (int) $schema->getConnection()
        ->table('flatrate_activity_outbox')
        ->insertGetId(outboxInsertPayload());
    assertTrue($id > 0, "{$label} outbox runtime insertGetId");

    $row = $schema->getConnection()->table('flatrate_activity_outbox')->where('id', $id)->first();
    assertTrue($row !== null && (string) $row->event_kind === 'reply_created', "{$label} outbox runtime readback");

    return $id;
}

function runtimeVoteStateInsert(Builder $schema, string $label): void
{
    $schema->getConnection()->table('flatrate_vote_activity_state')->insert([
        'post_id' => 80,
        'user_id' => 1,
        'last_effective_vote' => 1,
        'state_version' => 1,
        'updated_at' => '2026-09-16 00:00:00',
    ]);
    $row = $schema->getConnection()
        ->table('flatrate_vote_activity_state')
        ->where('post_id', 80)
        ->where('user_id', 1)
        ->first();
    assertTrue($row !== null && (int) $row->last_effective_vote === 1, "{$label} vote-state runtime insert/read");
}

function runEmptyPrefixCase(): void
{
    $label = 'ACTIVITY_PREFIX_EMPTY';
    $capsule = connectCapsule('');
    $schema = $capsule->schema();
    dropActivityLeftovers($schema);

    [$mig1, $mig2] = historicalMigrations();
    $mig1['up']($schema);
    $mig2['up']($schema);

    assertTrue(physicalExists($schema, 'flatrate_activity_outbox'), "{$label} historical physical outbox");
    assertTrue(physicalExists($schema, 'flatrate_vote_activity_state'), "{$label} historical physical vote-state");

    $repair = repairMigration();
    $repair['up']($schema);
    // Idempotent second apply.
    $repair['up']($schema);

    assertOutboxSchema($schema, $label);
    assertVoteStateSchema($schema, $label);
    runtimeOutboxInsert($schema, $label);
    runtimeVoteStateInsert($schema, $label);

    dropActivityLeftovers($schema);
}

function runNonEmptyPrefixCase(): void
{
    $label = 'ACTIVITY_PREFIX_NONEMPTY';
    $prefix = 'flarum_';
    $capsule = connectCapsule($prefix);
    $schema = $capsule->schema();
    dropActivityLeftovers($schema);

    [$mig1, $mig2] = historicalMigrations();
    $mig1['up']($schema);
    $mig2['up']($schema);

    // Negative control: historical raw DDL created unprefixed physical tables.
    assertTrue(
        physicalExists($schema, 'flatrate_activity_outbox'),
        'LEGACY_ACTIVITY_RAW_DDL_PREFIX_NEGATIVE_CONTROL legacy outbox exists'
    );
    assertTrue(
        ! $schema->hasTable('flatrate_activity_outbox'),
        'LEGACY_ACTIVITY_RAW_DDL_PREFIX_NEGATIVE_CONTROL runtime logical outbox missing'
    );
    assertTrue(
        ! physicalExists($schema, 'flarum_flatrate_activity_outbox'),
        'LEGACY_ACTIVITY_RAW_DDL_PREFIX_NEGATIVE_CONTROL prefixed outbox absent before repair'
    );

    // Sentinel in legacy unprefixed table — must survive repair untouched.
    $schema->getConnection()->statement(
        "INSERT INTO `flatrate_activity_outbox`
         (event_kind, flarum_user_id, payload_json, attempts, available_at, created_at, delivered_at, terminal_at, last_error_code)
         VALUES ('legacy_sentinel', 999, '{\"sentinel\":true}', 0, '2026-09-16 00:00:00', '2026-09-16 00:00:00', NULL, NULL, NULL)"
    );
    $legacyBefore = $schema->getConnection()->select(
        "SELECT id, event_kind FROM `flatrate_activity_outbox` WHERE event_kind = 'legacy_sentinel'"
    );
    assertTrue($legacyBefore !== [], 'LEGACY sentinel inserted');

    $repair = repairMigration();
    $repair['up']($schema);
    // Idempotency.
    $repair['up']($schema);

    assertTrue(
        physicalExists($schema, 'flarum_flatrate_activity_outbox'),
        "{$label} repaired prefixed outbox exists"
    );
    assertTrue(
        physicalExists($schema, 'flarum_flatrate_vote_activity_state'),
        "{$label} repaired prefixed vote-state exists"
    );
    assertTrue(
        physicalExists($schema, 'flatrate_activity_outbox'),
        'LEGACY_ACTIVITY_TABLE_PRESERVED_TEST legacy outbox still present'
    );

    $legacyAfter = $schema->getConnection()->select(
        "SELECT id, event_kind FROM `flatrate_activity_outbox` WHERE event_kind = 'legacy_sentinel'"
    );
    assertTrue($legacyAfter !== [], 'LEGACY_ACTIVITY_ROWS_NOT_BACKFILLED_TEST sentinel still in legacy');

    $prefixedSentinel = $schema->getConnection()->select(
        "SELECT id FROM `flarum_flatrate_activity_outbox` WHERE event_kind = 'legacy_sentinel'"
    );
    assertTrue($prefixedSentinel === [], 'LEGACY_ACTIVITY_ROWS_NOT_BACKFILLED_TEST sentinel not copied');

    assertOutboxSchema($schema, $label);
    assertVoteStateSchema($schema, $label);

    $id = runtimeOutboxInsert($schema, $label);
    $dest = $schema->getConnection()->select(
        'SELECT id FROM `flarum_flatrate_activity_outbox` WHERE id = ?',
        [$id]
    );
    assertTrue($dest !== [], "{$label} runtime insert landed in flarum_flatrate_activity_outbox");

    runtimeVoteStateInsert($schema, $label);
    $voteDest = $schema->getConnection()->select(
        'SELECT post_id FROM `flarum_flatrate_vote_activity_state` WHERE post_id = 80 AND user_id = 1'
    );
    assertTrue($voteDest !== [], "{$label} vote-state insert landed in flarum_flatrate_vote_activity_state");

    dropActivityLeftovers($schema);
}

$probe = waitForMariaDb();
$versionRow = $probe->getConnection()->selectOne('SELECT VERSION() AS v');
$version = (string) ((array) $versionRow)['v'];
echo "MARIADB_VERSION_TESTED={$version}\n";
echo "OUTBOX_RUNTIME_TEST=QUERY_BUILDER_EQUIVALENT\n";

runEmptyPrefixCase();
runNonEmptyPrefixCase();

if ($failures > 0) {
    echo "ACTIVITY_PREFIX_EMPTY_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "ACTIVITY_PREFIX_NONEMPTY_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "LEGACY_ACTIVITY_RAW_DDL_PREFIX_NEGATIVE_CONTROL=FAIL_OR_SEE_ABOVE\n";
    echo "ACTIVITY_OUTBOX_SCHEMA_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "ACTIVITY_OUTBOX_RUNTIME_INSERT_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "ACTIVITY_VOTE_STATE_RUNTIME_INSERT_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "LEGACY_ACTIVITY_TABLE_PRESERVED_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "LEGACY_ACTIVITY_ROWS_NOT_BACKFILLED_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "REPAIR_MIGRATION_IDEMPOTENCY_TEST=FAIL_OR_SEE_ABOVE\n";
    exit(1);
}

echo "ACTIVITY_PREFIX_EMPTY_TEST=PASS\n";
echo "ACTIVITY_PREFIX_NONEMPTY_TEST=PASS\n";
echo "LEGACY_ACTIVITY_RAW_DDL_PREFIX_NEGATIVE_CONTROL=PASS\n";
echo "ACTIVITY_OUTBOX_SCHEMA_TEST=PASS\n";
echo "ACTIVITY_OUTBOX_TERMINAL_AT_TEST=PASS\n";
echo "ACTIVITY_OUTBOX_INDEX_TEST=PASS\n";
echo "ACTIVITY_OUTBOX_RUNTIME_INSERT_TEST=PASS\n";
echo "ACTIVITY_VOTE_STATE_RUNTIME_INSERT_TEST=PASS\n";
echo "LEGACY_ACTIVITY_TABLE_PRESERVED_TEST=PASS\n";
echo "LEGACY_ACTIVITY_ROWS_NOT_BACKFILLED_TEST=PASS\n";
echo "REPAIR_MIGRATION_IDEMPOTENCY_TEST=PASS\n";
exit(0);
