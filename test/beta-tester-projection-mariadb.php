<?php

/**
 * Execute the beta projection migration and store against disposable MariaDB.
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
require dirname(__DIR__).'/src/Beta/BetaTesterProjectionTable.php';

use FlatRate\SupabaseOAuth\Beta\BetaTesterProjectionTable;
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
    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'mysql',
        'host' => envOr('MARIADB_HOST', '127.0.0.1'),
        'port' => envOr('MARIADB_PORT', '3306'),
        'database' => envOr('MARIADB_DATABASE', 'flarum_test'),
        'username' => envOr('MARIADB_USER', 'flarum'),
        'password' => envOr('MARIADB_PASSWORD', 'flarum'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => $prefix,
        'engine' => 'InnoDB',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule;
}

function loadMigration(string $path): array
{
    $migration = require $path;
    if (! is_array($migration) || ! isset($migration['up'], $migration['down'])) {
        throw new RuntimeException('invalid_migration '.$path);
    }

    return $migration;
}

function columnFact(Builder $schema, string $table, string $column): array
{
    $row = $schema->getConnection()->selectOne(
        'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?',
        [$table, $column]
    );
    if ($row === null) {
        throw new RuntimeException("missing_column {$table}.{$column}");
    }

    $facts = [];
    foreach ((array) $row as $key => $value) {
        $facts[strtoupper((string) $key)] = $value;
    }

    return $facts;
}

$root = dirname(__DIR__);
$users = loadMigration(__DIR__.'/fixtures/flarum-1.8.19-create-users-table.php');
$beta = loadMigration($root.'/migrations/2026_10_06_000000_create_beta_tester_projection.php');

$capsule = connectCapsule('');
$schema = $capsule->schema();
$db = $schema->getConnection();
$db->statement('DROP TABLE IF EXISTS `flatrate_beta_tester_access`');
$db->statement('DROP TABLE IF EXISTS `users`');

$users['up']($schema);
$beta['up']($schema);
assertTrue($schema->hasTable('flatrate_beta_tester_access'), 'migration creates table');

$userId = columnFact($schema, 'flatrate_beta_tester_access', 'user_id');
assertSame('PRI', $userId['COLUMN_KEY'], 'user_id is primary key');
assertTrue(str_contains(strtolower((string) $userId['COLUMN_TYPE']), 'int'), 'user_id is integer');
assertTrue(str_contains(strtolower((string) $userId['COLUMN_TYPE']), 'unsigned'), 'user_id is unsigned');

$active = columnFact($schema, 'flatrate_beta_tester_access', 'active');
assertSame('tinyint(1)', strtolower((string) $active['COLUMN_TYPE']), 'active boolean');
assertSame('NO', $active['IS_NULLABLE'], 'active not null');

$synced = columnFact($schema, 'flatrate_beta_tester_access', 'synced_at');
assertSame('timestamp', strtolower((string) $synced['DATA_TYPE']), 'synced_at exists');
assertSame('NO', $synced['IS_NULLABLE'], 'synced_at not null');

$fk = $db->selectOne(
    'SELECT kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
     FROM information_schema.REFERENTIAL_CONSTRAINTS rc
     JOIN information_schema.KEY_COLUMN_USAGE kcu
       ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
      AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
     WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
       AND kcu.TABLE_NAME = ?
       AND kcu.COLUMN_NAME = ?',
    ['flatrate_beta_tester_access', 'user_id']
);
assertTrue($fk !== null, 'FK users.id exists');
if ($fk !== null) {
    $facts = [];
    foreach ((array) $fk as $key => $value) {
        $facts[strtoupper((string) $key)] = $value;
    }
    assertSame('users', $facts['REFERENCED_TABLE_NAME'], 'FK references users');
    assertSame('id', $facts['REFERENCED_COLUMN_NAME'], 'FK references users.id');
    assertSame('CASCADE', $facts['DELETE_RULE'], 'FK cascade delete');
}

foreach (['email', 'sub', 'phone', 'username', 'access_token', 'refresh_token', 'reason', 'notes'] as $leaked) {
    $exists = $db->selectOne(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['flatrate_beta_tester_access', $leaked]
    );
    assertTrue($exists === null, "no leaked column {$leaked}");
}

$inserted = (int) $db->table('users')->insertGetId([
    'username' => 'beta_projection_user',
    'email' => 'beta-projection@example.test',
    'is_activated' => 1,
    'password' => 'x',
    'discussions_count' => 0,
    'comments_count' => 0,
]);

$rows = new BetaTesterProjectionTable($db);
assertTrue($rows->isActiveId($inserted) === false, 'missing row is inactive');
assertTrue($rows->syncId($inserted, true) === true, 'sync true creates active row');
assertTrue($rows->isActiveId($inserted) === true, 'created row is active');
assertTrue($rows->syncId($inserted, true) === false, 'sync true when already true is unchanged');
$firstSynced = $rows->syncedAt($inserted);
sleep(1);
assertTrue($rows->syncId($inserted, true) === false, 'idempotent true still unchanged');
$secondSynced = $rows->syncedAt($inserted);
assertTrue($firstSynced !== null && $secondSynced !== null && $secondSynced !== $firstSynced, 'synced_at updates');
assertTrue($rows->syncId($inserted, false) === true, 'sync false changes true to false');
assertTrue($rows->isActiveId($inserted) === false, 'revoked row is inactive');
assertTrue($db->table('flatrate_beta_tester_access')->where('user_id', $inserted)->exists(), 'revoke retains row');
assertTrue($rows->syncId($inserted, false) === false, 'sync false when already false is unchanged');

$db->table('users')->where('id', $inserted)->delete();
assertTrue(
    $db->table('flatrate_beta_tester_access')->where('user_id', $inserted)->doesntExist(),
    'user delete cascades row'
);

$beta['down']($schema);
assertTrue(! $schema->hasTable('flatrate_beta_tester_access'), 'migration down removes table');
$db->statement('DROP TABLE IF EXISTS `users`');

if ($failures > 0) {
    fwrite(STDERR, "BETA_PROJECTION_MARIADB_FAILURES={$failures}\n");
    exit(1);
}

echo "BETA_PROJECTION_MARIADB=PASS\n";
exit(0);
