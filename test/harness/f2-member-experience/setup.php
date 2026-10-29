<?php
declare(strict_types=1);
$root = getenv('F2_LAB_ROOT') ?: '';
if (!str_starts_with($root, '/tmp/') || file_exists($root.'/app/config.php')) throw new RuntimeException('Fresh disposable lab required');
$host = getenv('F2_DB_HOST') ?: '127.0.0.1';
if (!in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Loopback DB required');
$name = getenv('F2_DB_NAME') ?: '';
if (!preg_match('/^fr_f2_acceptance_[a-z0-9_]+$/D', $name)) throw new RuntimeException('Disposable database name required');
$user = getenv('F2_DB_USER') ?: 'root'; $password = getenv('F2_DB_PASSWORD') ?: '';
$port = (int) (getenv('F2_DB_PORT') ?: 3306);
$socket = getenv('F2_DB_SOCKET') ?: '';
$pdo = new PDO($socket ? 'mysql:unix_socket='.$socket : "mysql:host={$host};port={$port}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?'); $exists->execute([$name]);
if ($exists->fetchColumn()) throw new RuntimeException('Refusing to overwrite existing database');
$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$base = getenv('F2_BASE_URL') ?: 'http://127.0.0.1:18767';
if (parse_url($base, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Loopback HTTP required');
$config = [
    'debug' => true, 'baseUrl' => $base,
    'databaseConfiguration' => ['driver' => 'mariadb', 'host' => $socket ? 'localhost' : $host, 'port' => $port, 'database' => $name, 'username' => $user, 'password' => $password, 'prefix' => 'flarum_'],
    'adminUser' => ['username' => 'qualadmin', 'email' => 'qualadmin@example.test', 'password' => bin2hex(random_bytes(24))],
    'extensions' => 'flarum-lang-english,flarum-tags,flarum-bbcode,flarum-emoji,flarum-markdown,flarum-mentions,flarum-statistics,flarum-sticky,flarum-subscriptions,flarum-suspend,flarum-nicknames',
    'settings' => ['forum_title' => 'FlatRate disposable qualification', 'mail_driver' => 'log'],
];
$path = $root.'/install.json'; file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); chmod($path, 0600);
echo "F2_DISPOSABLE_DATABASE=CREATED\n";
