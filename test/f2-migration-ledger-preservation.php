<?php

declare(strict_types=1);

$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
if (! str_starts_with($root, '/tmp/')) throw new RuntimeException('Disposable FLARUM_ROOT required');
chdir($root); require_once $root.'/vendor/autoload.php';
$site = require $root.'/site.php'; $app = $site->bootApp();
$baseline = $app->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
if (!str_starts_with($baseline->getDatabaseName(), 'fr_f2_acceptance_')) throw new RuntimeException('Unexpected database');
$owner = $baseline->getPdo();
$names = []; $checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL '.$name); $checks++; echo 'PASS '.$name."\n"; };
$old = '2026_09_01_000000_widen_masquerade_field_validation';
$repair = '2026_10_28_000000_repair_masquerade_field_validation_text';
$stage = sys_get_temp_dir().'/fr-f2-migrations-'.bin2hex(random_bytes(6)); mkdir($stage);
foreach ([$old, $repair] as $file) copy(dirname(__DIR__).'/migrations/'.$file.'.php', $stage.'/'.$file.'.php');
try {
    foreach (['', 'flarum_'] as $prefix) {
        $name = 'fr_f2_migration_'.bin2hex(random_bytes(6)); $names[] = $name;
        $owner->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection(array_merge($baseline->getConfig(), ['database' => $name, 'prefix' => $prefix]));
        $connection = $capsule->getConnection(); $schema = $connection->getSchemaBuilder();
        $repository = new \Flarum\Database\DatabaseMigrationRepository($connection, 'migrations'); $repository->createRepository();
        $migrator = new \Flarum\Database\Migrator($repository, $connection, new \Illuminate\Filesystem\Filesystem());
        $migrator->runMigrationList($stage, [$old]);
        $check(in_array($old, $repository->getRan(), true) && !$schema->hasTable('fof_masquerade_fields'), 'historical guarded no-op is recorded before Masquerade exists: prefix '.$prefix);
        $schema->create('users', static fn (\Illuminate\Database\Schema\Blueprint $table) => $table->increments('id'));
        foreach (['2019_06_10_03_create_fields_table', '2019_06_10_04_create_answers_table'] as $file) {
            $migration = require $root.'/vendor/fof/masquerade/migrations/'.$file.'.php'; $migration['up']($schema);
        }
        $connection->table('users')->insert(['id' => 41]);
        $connection->table('fof_masquerade_fields')->insert(['id' => 17, 'name' => 'Affiliated Brand', 'type' => 'select', 'validation' => 'in:Chevrolet,Ford', 'sort' => 4, 'on_bio' => 0, 'required' => 1]);
        $connection->table('fof_masquerade_answers')->insert(['id' => 29, 'field_id' => 17, 'user_id' => 41, 'content' => 'Ford']);
        $beforeField = (array) $connection->table('fof_masquerade_fields')->where('id', 17)->first();
        $beforeAnswer = (array) $connection->table('fof_masquerade_answers')->where('id', 29)->first();
        $migrator->run($stage);
        $columns = $schema->getColumns('fof_masquerade_fields');
        $validation = array_values(array_filter($columns, static fn ($column) => $column['name'] === 'validation'))[0];
        $check(strtolower($validation['type_name']) === 'text', 'forward repair handles recorded no-op: prefix '.$prefix);
        $check((array) $connection->table('fof_masquerade_fields')->where('id', 17)->first() === $beforeField, 'field identity, order, flags and validation preserved: prefix '.$prefix);
        $check((array) $connection->table('fof_masquerade_answers')->where('id', 29)->first() === $beforeAnswer, 'existing answer identity, owner, field and value preserved: prefix '.$prefix);
        $longRule = 'in:'.implode(',', array_fill(0, 70, 'BrandOption'));
        $connection->table('fof_masquerade_fields')->where('id', 17)->update(['validation' => $longRule]);
        $migrator->run($stage);
        $check(count($repository->getRan()) === 2, 'Flarum ledger makes rerun idempotent: prefix '.$prefix);
        $migration = require $stage.'/'.$repair.'.php'; $migration['down']($schema);
        $check($connection->table('fof_masquerade_fields')->where('id', 17)->value('validation') === $longRule, 'repair rollback retains long validation: prefix '.$prefix);
        $check((array) $connection->table('fof_masquerade_answers')->where('id', 29)->first() === $beforeAnswer, 'answer remains intact after rerun and repair rollback: prefix '.$prefix);
        $ballots = require dirname(__DIR__).'/migrations/2026_10_29_000000_native_plain_votes.php';
        $ballots['up']($schema);
        $check($schema->hasTable('post_votes'), 'native vote table created with correct prefix: '.$prefix);
        $connection->table('post_votes')->insert(['id' => 58, 'post_id' => 27, 'user_id' => 41, 'value' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00']);
        $ballot = (array) $connection->table('post_votes')->where('id', 58)->first();
        $ballots['up']($schema); $ballots['down']($schema);
        $check((array) $connection->table('post_votes')->where('id', 58)->first() === $ballot, 'native migration preserves historical ballot identity, value and timestamps: '.$prefix);
        $duplicateDenied = false;
        try { $connection->table('post_votes')->insert(['post_id' => 27, 'user_id' => 41, 'value' => 1]); }
        catch (\Illuminate\Database\QueryException $e) { $duplicateDenied = $e->getCode() === '23000'; }
        $check($duplicateDenied && $connection->table('post_votes')->count() === 1, 'unique ballot boundary survives rerun: '.$prefix);
        $connection->disconnect();
    }
    echo "F2_MIGRATION_LEDGER_PRESERVATION=PASS CHECKS={$checks}\n";
} finally {
    foreach ($names as $name) $owner->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    foreach (glob($stage.'/*.php') as $file) unlink($file);
    rmdir($stage);
}
