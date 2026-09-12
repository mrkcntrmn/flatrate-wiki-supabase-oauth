<?php

declare(strict_types=1);

$flarumDir = $argv[1] ?? '';
$outFile = $argv[2] ?? '';
if ($flarumDir === '' || $outFile === '') {
    fwrite(STDERR, "usage: php inspect-assets.php <flarum-dir> <assets.json>\n");
    exit(1);
}

$site = require $flarumDir.'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();
$assets = $container->make('flarum.assets.forum');
$assets->makeJs()->commit();
$assets->makeCss()->commit();

$forumJs = $flarumDir.'/public/assets/forum.js';
if (!is_file($forumJs) || filesize($forumJs) < 1000) {
    fwrite(STDERR, "compiled forum.js not found or empty\n");
    exit(1);
}

$source = (string) file_get_contents($forumJs);
$initCount = preg_match_all('/flatrate-wiki-member-display/', $source, $m);
$apiCount = preg_match_all('/flatrate\/member-display/', $source, $m2);
$exportCount = preg_match_all('/module\.exports = \{\};/', $source, $m3);

if ($initCount !== 1) {
    fwrite(STDERR, "MEMBER_DISPLAY_INITIALIZER_COUNT=$initCount\n");
    exit(1);
}
if ($apiCount < 1) {
    fwrite(STDERR, "MEMBER_DISPLAY_API_PATH_MISSING\n");
    exit(1);
}

$payload = [
    'forumJs' => $forumJs,
    'initializerCount' => $initCount,
    'apiPathCount' => $apiCount,
    'moduleExportsEmptyObjectCount' => $exportCount,
];
file_put_contents($outFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, "PRODUCTION_ASSET_SHAPE=PASS\n");
fwrite(STDOUT, "MEMBER_DISPLAY_CODE_PRESENT_ONCE=true\n");
fwrite(STDOUT, "INITIALIZER_REGISTERED_ONCE=true\n");
