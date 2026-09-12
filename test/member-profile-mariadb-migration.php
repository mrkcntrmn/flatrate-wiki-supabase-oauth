<?php

/**
 * FORUM-IDENTITY-002-R2: execute the real member-profile migration against MariaDB.
 *
 * Proves prefix-aware schema-builder DDL, FK type match, cascade, down, and that
 * the old raw `REFERENCES users (id)` migration fails when a prefix is configured.
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
use Illuminate\Database\QueryException;
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

function columnFact(Builder $schema, string $table, string $column): array
{
    $row = $schema->getConnection()->selectOne(
        'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
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

function dropLeftovers(Builder $schema): void
{
    $db = $schema->getConnection();
    foreach ([
        'flatrate_member_profiles',
        'flarum_flatrate_member_profiles',
        'users',
        'flarum_users',
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

function insertUser(Builder $schema, string $username): int
{
    return (int) $schema->getConnection()->table('users')->insertGetId([
        'username' => $username,
        'email' => $username.'@example.test',
        'is_activated' => 1,
        'password' => 'x',
        'discussions_count' => 0,
        'comments_count' => 0,
    ]);
}

function insertProfile(Builder $schema, int $userId, int $memberNumber, string $mode = 'member_number'): void
{
    $now = '2026-09-12 00:00:00';
    $schema->getConnection()->table('flatrate_member_profiles')->insert([
        'user_id' => $userId,
        'member_number' => $memberNumber,
        'display_mode' => $mode,
        'custom_nickname' => null,
        'custom_nickname_origin' => null,
        'assigned_at' => $now,
        'updated_at' => $now,
    ]);
}

function runCase(string $label, string $prefix): void
{
    $capsule = connectCapsule($prefix);
    $schema = $capsule->schema();
    dropLeftovers($schema);

    $users = loadMigration(__DIR__.'/fixtures/flarum-1.8.19-create-users-table.php');
    $users['up']($schema);
    assertTrue($schema->hasTable('users'), "{$label} users table exists");

    $member = loadMigration(dirname(__DIR__).'/migrations/2026_09_12_000000_create_flatrate_member_profiles.php');
    $member['up']($schema);
    assertTrue($schema->hasTable('flatrate_member_profiles'), "{$label} member profiles table exists");

    $usersTable = physical($schema, 'users');
    $profilesTable = physical($schema, 'flatrate_member_profiles');
    assertSame($prefix.'users', $usersTable, "{$label} users physical name");
    assertSame($prefix.'flatrate_member_profiles', $profilesTable, "{$label} profiles physical name");

    $userId = columnFact($schema, $usersTable, 'id');
    $profileUserId = columnFact($schema, $profilesTable, 'user_id');
    $memberNumber = columnFact($schema, $profilesTable, 'member_number');

    assertSame($userId['COLUMN_TYPE'], $profileUserId['COLUMN_TYPE'], "{$label} user_id COLUMN_TYPE matches users.id");
    assertSame($userId['DATA_TYPE'], $profileUserId['DATA_TYPE'], "{$label} user_id DATA_TYPE matches users.id");
    assertTrue(
        str_contains(strtolower((string) $userId['COLUMN_TYPE']), 'unsigned')
        && str_contains(strtolower((string) $profileUserId['COLUMN_TYPE']), 'unsigned'),
        "{$label} user_id and users.id are unsigned"
    );
    assertSame($userId['COLUMN_TYPE'], $memberNumber['COLUMN_TYPE'], "{$label} member_number capacity matches users.id");
    assertTrue(
        ! str_contains(strtolower((string) $memberNumber['EXTRA']), 'auto_increment'),
        "{$label} member_number is not AUTO_INCREMENT"
    );

    $fk = $schema->getConnection()->selectOne(
        'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL',
        [$profilesTable, 'user_id']
    );
    assertTrue($fk !== null, "{$label} foreign key exists");
    if ($fk !== null) {
        $fk = (array) $fk;
        assertSame($profilesTable, $fk['TABLE_NAME'], "{$label} FK child table");
        assertSame('user_id', $fk['COLUMN_NAME'], "{$label} FK child column");
        assertSame($usersTable, $fk['REFERENCED_TABLE_NAME'], "{$label} FK parent table");
        assertSame('id', $fk['REFERENCED_COLUMN_NAME'], "{$label} FK parent column");
    }

    $deleteRule = $schema->getConnection()->selectOne(
        'SELECT DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = ?',
        [$profilesTable]
    );
    assertTrue($deleteRule !== null && strtoupper((string) ((array) $deleteRule)['DELETE_RULE']) === 'CASCADE', "{$label} ON DELETE CASCADE");

    $userIdValue = insertUser($schema, $label === 'CASE_B' ? 'tech_prefix' : 'tech_empty');
    insertProfile($schema, $userIdValue, $userIdValue);
    $row = $schema->getConnection()->table('flatrate_member_profiles')->where('user_id', $userIdValue)->first();
    assertTrue($row !== null && (int) $row->member_number === $userIdValue, "{$label} matching profile insert");

    $orphanRejected = false;
    try {
        insertProfile($schema, $userIdValue + 1000, $userIdValue + 1000);
    } catch (QueryException $e) {
        $orphanRejected = true;
    }
    assertTrue($orphanRejected, "{$label} rejects orphan user_id");

    $checkUser = insertUser($schema, ($label === 'CASE_B' ? 'tech_prefix_chk' : 'tech_empty_chk'));
    $zeroRejected = false;
    try {
        insertProfile($schema, $checkUser, 0);
    } catch (QueryException $e) {
        $zeroRejected = true;
    }
    assertTrue($zeroRejected, "{$label} CHECK member_number > 0");

    $schema->getConnection()->table('users')->where('id', $userIdValue)->delete();
    $afterDelete = $schema->getConnection()->table('flatrate_member_profiles')->where('user_id', $userIdValue)->first();
    assertTrue($afterDelete === null, "{$label} cascade deletes profile");

    $member['down']($schema);
    assertTrue(! $schema->hasTable('flatrate_member_profiles'), "{$label} down drops table");

    dropLeftovers($schema);
}

function runNegativeControl(): void
{
    $capsule = connectCapsule('flarum_');
    $schema = $capsule->schema();
    dropLeftovers($schema);

    $users = loadMigration(__DIR__.'/fixtures/flarum-1.8.19-create-users-table.php');
    $users['up']($schema);

    $rawSql = 'CREATE TABLE IF NOT EXISTS flatrate_member_profiles (
            user_id INT UNSIGNED NOT NULL,
            member_number INT UNSIGNED NOT NULL,
            display_mode VARCHAR(32) NOT NULL,
            custom_nickname VARCHAR(255) NULL,
            custom_nickname_origin VARCHAR(32) NULL,
            assigned_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            UNIQUE KEY flatrate_member_profiles_member_number_unique (member_number),
            CONSTRAINT flatrate_member_profiles_user_id_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT flatrate_member_profiles_member_number_positive
                CHECK (member_number > 0),
            CONSTRAINT flatrate_member_profiles_display_mode_chk
                CHECK (display_mode IN (\'member_number\', \'custom\')),
            CONSTRAINT flatrate_member_profiles_origin_chk
                CHECK (custom_nickname_origin IN (\'grandfathered\', \'user\') OR custom_nickname_origin IS NULL)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $failed = false;
    $detail = '';
    try {
        $schema->getConnection()->statement($rawSql);
    } catch (QueryException $e) {
        $failed = true;
        $detail = $e->getMessage();
    }

    assertTrue($failed, 'NEGATIVE raw REFERENCES users (id) fails under prefix flarum_');
    assertTrue(
        str_contains($detail, '150') || str_contains($detail, 'Foreign key constraint is incorrectly formed'),
        'NEGATIVE reproduces errno 150',
        $detail
    );
    assertTrue(! $schema->hasTable('flatrate_member_profiles'), 'NEGATIVE did not create prefixed profiles table');

    $unprefixed = $schema->getConnection()->select(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flatrate_member_profiles'"
    );
    assertTrue($unprefixed === [], 'NEGATIVE did not persist unprefixed table');

    dropLeftovers($schema);
}

$probe = waitForMariaDb();
$versionRow = $probe->getConnection()->selectOne('SELECT VERSION() AS v');
$version = (string) ((array) $versionRow)['v'];
echo "MARIADB_VERSION_TESTED={$version}\n";

runCase('CASE_A', '');
runCase('CASE_B', 'flarum_');
runNegativeControl();

if ($failures > 0) {
    echo "MARIADB_MIGRATION_TEST=FAIL\n";
    echo "PREFIX_EMPTY_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "PREFIX_NONEMPTY_TEST=FAIL_OR_SEE_ABOVE\n";
    echo "MIGRATION_HARNESS_NEGATIVE_CONTROL=FAIL_OR_SEE_ABOVE\n";
    exit(1);
}

echo "MARIADB_MIGRATION_TEST=PASS\n";
echo "MARIADB_PREFIXED_MIGRATION_TEST=PASS\n";
echo "MARIADB_FOREIGN_KEY_TEST=PASS\n";
echo "MARIADB_CASCADE_TEST=PASS\n";
echo "MARIADB_DOWN_MIGRATION_TEST=PASS\n";
echo "PREFIX_EMPTY_TEST=PASS\n";
echo "PREFIX_NONEMPTY_TEST=PASS\n";
echo "FOREIGN_KEY_EXISTS_TEST=PASS\n";
echo "FOREIGN_KEY_TYPE_MATCH_TEST=PASS\n";
echo "ON_DELETE_CASCADE_TEST=PASS\n";
echo "DOWN_MIGRATION_TEST=PASS\n";
echo "MIGRATION_HARNESS_NEGATIVE_CONTROL=PASS\n";
exit(0);
