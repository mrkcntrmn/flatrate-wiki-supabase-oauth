<?php

/**
 * FORUM-SUB-000D — Flarum 1.8 Frontend::js() scalar-overwrite source gate.
 *
 * Prefers vendor/flarum/core when present; otherwise uses the pinned
 * v1.8.19 fixture (identical SHA to flarum/framework tag v1.8.19).
 *
 * Companion extend.php is not require()'d here: that needs flarum/core +
 * fof/oauth installed. Structural registration order is enforced by
 * test/frontend-js-registration.test.mjs.
 */

$failures = 0;

function expect(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDERR, "[PASS] {$message}\n");
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}\n");
}

$root = dirname(__DIR__);
$vendor = $root.'/vendor/flarum/core/src/Extend/Frontend.php';
$fixture = $root.'/test/fixtures/flarum-1.8.19-Extend-Frontend.php';

if (is_file($vendor)) {
    $path = $vendor;
    $source = 'vendor';
} else {
    expect(is_file($fixture), 'pinned Flarum 1.8.19 Frontend.php fixture exists');
    $path = $fixture;
    $source = 'fixture';
}

$api = file_get_contents($path);
expect($api !== false && $api !== '', 'Frontend.php readable');

expect((bool) preg_match('/private\s+\$css\s*=\s*\[\];/', $api), 'css property is an array');
expect((bool) preg_match('/private\s+\$js;/', $api), 'js property is an uninitialized scalar');
expect(!preg_match('/private\s+\$js\s*=\s*\[/', $api), 'js property is not an array');

expect(
    (bool) preg_match('/public function css\(string \$path\): self\s*\{[^}]*\$this->css\[\]\s*=\s*\$path;/s', $api),
    'css() appends to \$css'
);
expect(
    (bool) preg_match('/public function js\(string \$path\): self\s*\{[^}]*\$this->js\s*=\s*\$path;/s', $api),
    'js() overwrites \$js'
);
expect(
    !preg_match('/public function js\(string \$path\): self\s*\{[^}]*\$this->js\[\]\s*=/s', $api),
    'js() does not append'
);

fwrite(STDERR, "FLARUM_FRONTEND_SOURCE={$source}:{$path}\n");
fwrite(STDERR, "FLARUM_FRONTEND_JS_PROPERTY_IS_SCALAR=true\n");
fwrite(STDERR, "FLARUM_FRONTEND_JS_METHOD_OVERWRITES=true\n");
fwrite(STDERR, "FLARUM_FRONTEND_CSS_METHOD_APPENDS=true\n");

if ($failures > 0) {
    fwrite(STDERR, "frontend-js-registration.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "frontend-js-registration.php: all checks passed\n");
exit(0);
