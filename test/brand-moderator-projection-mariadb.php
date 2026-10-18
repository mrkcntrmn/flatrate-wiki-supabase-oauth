<?php
/**
 * Disposable MariaDB qualification for exact-Brand projection.
 * No production DB, roles, invitations, or grants are used.
 */
declare(strict_types=1);

$autoload = __DIR__.'/harness/mariadb-migration/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "MARIADB_HARNESS_MISSING_VENDOR\n");
    exit(2);
}
require $autoload;
require __DIR__.'/fixtures/flarum-1.8.19-Migration.php';
require dirname(__DIR__).'/src/Brand/BrandModeratorProjectionTable.php';

use FlatRate\SupabaseOAuth\Brand\BrandModeratorProjectionTable;
use Illuminate\Database\Capsule\Manager as Capsule;

$failures = 0;
function checkBrand(bool $condition, string $case): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo "[FAIL] $case\n";
    } else {
        echo "[PASS] $case\n";
    }
}
function envBrand(string $key, string $default): string
{
    $s = getenv($key);
    return $s === false || $s === '' ? $default : $s;
}

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => envBrand('MARIADB_HOST', '127.0.0.1'),
    'port' => envBrand('MARIADB_PORT', '3306'),
    'database' => envBrand('MARIADB_DATABASE', 'flarum_test'),
    'username' => envBrand('MARIADB_USER', 'flarum'),
    'password' => envBrand('MARIADB_PASSWORD', 'flarum'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'engine' => 'InnoDB',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$schema = $capsule->schema();
$users = require __DIR__.'/fixtures/flarum-1.8.19-create-users-table.php';
$migration = require dirname(__DIR__).'/migrations/2026_10_18_000000_create_brand_moderator_projection.php';

$db->statement('DROP TABLE IF EXISTS `flatrate_brand_moderator_access`');
$db->statement('DROP TABLE IF EXISTS `users`');
$users['up']($schema);
$migration['up']($schema);
checkBrand($schema->hasTable('flatrate_brand_moderator_access'), 'Brand projection table created');

$id = (int) $db->table('users')->insertGetId([
    'username' => 'brand_mod_test',
    'email' => 'brand-mod@example.test',
    'is_activated' => 1,
    'password' => 'x',
    'discussions_count' => 0,
    'comments_count' => 0,
]);
$rows = new BrandModeratorProjectionTable($db);
checkBrand(!$rows->isActiveId($id, 'ford'), 'missing grant denied');
checkBrand(BrandModeratorProjectionTable::validBrandKey('ford'), 'canonical key accepted');
checkBrand(!BrandModeratorProjectionTable::validBrandKey('FORD'), 'uppercase key rejected');
checkBrand(!BrandModeratorProjectionTable::validBrandKey('ford/live'), 'path-like key rejected');
checkBrand($rows->syncId($id, 'ford', true, 1), 'revision 1 signed grant stored');
checkBrand($rows->isActiveId($id, 'ford'), 'fresh Ford grant active');
checkBrand(!$rows->isActiveId($id, 'chevrolet'), 'no cross-Brand privilege');
checkBrand(!$rows->syncId($id, 'ford', false, 1), 'duplicate revision cannot change access');
checkBrand($rows->syncId($id, 'ford', false, 2), 'higher revision revokes');
checkBrand(!$rows->isActiveId($id, 'ford'), 'revocation immediately denies');
checkBrand(!$rows->syncId($id, 'ford', true, 1), 'stale grant cannot undo revocation');
checkBrand(!$rows->isActiveId($id, 'ford'), 'replayed grant still denied');
checkBrand($rows->syncId($id, 'ford', true, 3), 'new higher grant revision accepted');
checkBrand($rows->isActiveId($id, 'ford'), 'new valid revision active');

$db->table('flatrate_brand_moderator_access')->where('user_id', $id)
    ->where('brand_key', 'ford')
    ->update(['synced_at' => gmdate('Y-m-d H:i:s', time() - 600)]);
checkBrand(!$rows->isActiveId($id, 'ford'), 'stale projection denies moderator authority');

checkBrand($rows->syncId($id, 'ford', true, 4), 'monotonic heartbeat renews lease');
checkBrand($rows->isActiveId($id, 'ford'), 'renewed grant active');
checkBrand(!$rows->syncId($id, 'ford', true, 4), 'same revision cannot extend lease');

foreach (['sub', 'email', 'token', 'raw_invite', 'phone'] as $privateField) {
    checkBrand(!$schema->hasColumn('flatrate_brand_moderator_access', $privateField), "no $privateField in projection");
}
$db->table('users')->where('id', $id)->delete();
checkBrand(
    !$db->table('flatrate_brand_moderator_access')->where('user_id', $id)->exists(),
    'deleting user cascades grant projection'
);
$migration['down']($schema);
checkBrand(!$schema->hasTable('flatrate_brand_moderator_access'), 'migration down cleanup');
$db->statement('DROP TABLE IF EXISTS `users`');

if ($failures > 0) {
    fwrite(STDERR, "BRAND_MODERATOR_MARIADB_FAIL=$failures\n");
    exit(1);
}
echo "BRAND_MODERATOR_MARIADB=PASS\n";
