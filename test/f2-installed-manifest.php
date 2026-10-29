<?php
declare(strict_types=1);
$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
if (!str_starts_with($root, '/tmp/')) throw new RuntimeException('Disposable lab required');
chdir($root); require $root.'/vendor/autoload.php'; $app = (require $root.'/site.php')->bootApp();
$db = $app->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
if (!str_starts_with($db->getDatabaseName(), 'fr_f2_acceptance_')) throw new RuntimeException('Unexpected database');
$settings = $app->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
$enabled = json_decode($settings->get('extensions_enabled'), true, 512, JSON_THROW_ON_ERROR); sort($enabled);
$gates = [];
foreach (['flatrate-voting.enabled', 'flatrate-activity.emit_enabled', 'masquerade.force-profile-completion', 'fof-sitemap.excludeUsers'] as $key) $gates[$key] = (bool) $settings->get($key);
$assets = [];
foreach (glob($root.'/public/assets/*.{js,css}', GLOB_BRACE) as $path) $assets[basename($path)] = ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
ksort($assets);
echo json_encode([
    'flarum' => \Flarum\Foundation\Application::VERSION, 'php' => PHP_VERSION,
    'databaseVersion' => $db->getPdo()->query('SELECT VERSION()')->fetchColumn(), 'tablePrefix' => $db->getTablePrefix(),
    'enabledExtensions' => $enabled, 'gates' => $gates,
    'migrations' => $db->table('migrations')->orderBy('extension')->orderBy('migration')->get(['extension', 'migration'])->all(),
    'assets' => $assets, 'installedLockSha256' => hash_file('sha256', $root.'/composer.lock'),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
