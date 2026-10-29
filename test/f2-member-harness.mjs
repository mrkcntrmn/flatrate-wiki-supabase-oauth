import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdirSync, existsSync, copyFileSync, symlinkSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync, spawn } from 'node:child_process';
const source = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const template = resolve(source, 'test/harness/f2-member-experience');
const root = process.env.F2_LAB_ROOT;
assert.ok(root?.startsWith('/tmp/') && !existsSync(root), 'New /tmp lab directory required');
const sources = JSON.parse(process.env.F2_SOURCES_JSON || '{}');
sources.oauth = source;
for (const name of ['oauth', 'navigation', 'messaging', 'dm', 'live', 'media']) assert.ok(typeof sources[name] === 'string' && existsSync(resolve(sources[name], 'composer.json')), 'Explicit source directory required: ' + name);
const app = resolve(root, 'app'); const evidence = resolve(root, 'evidence');
for (const path of [app, evidence, resolve(root, 'sources'), ...['public/assets', 'storage/cache', 'storage/formatter', 'storage/logs', 'storage/sessions', 'storage/locale', 'storage/views'].map(p => resolve(app, p))]) mkdirSync(path, { recursive: true });
for (const [name, path] of Object.entries(sources)) symlinkSync(resolve(path), resolve(root, 'sources', name));
for (const file of ['composer.json', 'composer.lock']) copyFileSync(resolve(template, file), resolve(app, file));
writeFileSync(resolve(app, 'site.php'), `<?php\nrequire __DIR__.'/vendor/autoload.php';\nreturn Flarum\\Foundation\\Site::fromPaths(['base'=>__DIR__,'public'=>__DIR__.'/public','storage'=>__DIR__.'/storage']);\n`);
writeFileSync(resolve(app, 'flarum'), `<?php\n$site=require __DIR__.'/site.php';\n(new Flarum\\Console\\Server($site))->listen();\n`);
writeFileSync(resolve(app, 'public/index.php'), `<?php\n$site=require __DIR__.'/../site.php';\n(new Flarum\\Http\\Server($site))->listen();\n`);
writeFileSync(resolve(app, 'public/router.php'), `<?php\n$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);\nif($path!=='/' && is_file(__DIR__.$path))return false;\nrequire __DIR__.'/index.php';\n`);
const php = process.env.PHP_BINARY || 'php'; const ini = process.env.F2_PHP_INI ? ['-c', process.env.F2_PHP_INI] : [];
const env = { ...process.env, FLARUM_ROOT: app, F2_AUTH_FILE: resolve(root, 'auth.json'), F2_INSTALL_FILE: resolve(root, 'install.json'), F2_EVIDENCE_DIR: evidence, CONTRACT_MEMBER: 'tech_qual42', CONTRACT_DISCUSSION: '1' };
function run(command, args, options = {}) { execFileSync(command, args, { cwd: app, env, stdio: 'inherit', ...options }); }
const composer = process.env.COMPOSER_BINARY || execFileSync('which', ['composer'], { encoding: 'utf8' }).trim();
// Refresh path metadata for this checkout, preserving every locked external version.
const before = JSON.parse(readFileSync(resolve(app, 'composer.lock'))).packages.filter(p => p.dist?.type !== 'path').map(p => [p.name, p.version, p.source?.reference || p.dist?.reference]);
run(php, [...ini, composer, 'update', '--lock', '--no-install', '--no-interaction']);
const after = JSON.parse(readFileSync(resolve(app, 'composer.lock'))).packages.filter(p => p.dist?.type !== 'path').map(p => [p.name, p.version, p.source?.reference || p.dist?.reference]);
assert.deepEqual(after, before, 'External package lock must remain identical');
run(php, [...ini, composer, 'install', '--no-interaction', '--prefer-dist']);
run(php, [...ini, composer, 'check-platform-reqs']);
run(php, [...ini, resolve(template, 'setup.php')]);
run(php, [...ini, 'flarum', 'install', '--file=' + env.F2_INSTALL_FILE]);
// Enable third-party extensions after core installation, with an initialized ORM.
for (const id of ['fof-masquerade', 'flatrate-wiki-supabase-oauth', 'fof-follow-tags', 'fof-formatting', 'fof-polls', 'fof-reactions', 'fof-seo', 'fof-sitemap', 'fof-upload', 'flatrate-forum-navigation', 'flatrate-private-messages-bridge', 'flatrate-messaging-ui', 'flatrate-media-privacy', 'flarum-extension-manager']) run(php, [...ini, 'flarum', 'extension:enable', id]);
run(php, [...ini, resolve(source, 'test/f2-member-experience-seed.php')]);
for (const name of ['f2-resource-contract-boot.php', 'f2-member-features-boot.php', 'f2-native-voting-boot.php', 'f2-upload-privacy-boot.php', 'f2-masquerade-completion-boot.php', 'f2-migration-ledger-preservation.php']) run(php, [...ini, resolve(source, 'test', name)]);
const base = new URL(process.env.F2_BASE_URL || 'http://127.0.0.1:18767');
const server = spawn(php, [...ini, '-S', base.host, '-t', 'public', 'public/router.php'], { cwd: app, env, stdio: ['ignore', 'ignore', 'ignore'] });
try {
  for (let attempt = 0; ; attempt++) { try { const r = await fetch(base); if (r.status === 200) break; } catch {} if (attempt >= 30) throw new Error('Local acceptance server unavailable'); await new Promise(r => setTimeout(r, 1000)); }
  for (const name of ['f2-identity-http.mjs', 'f2-crawl-http.mjs', 'f2-member-experience-browser.mjs']) run(process.execPath, [resolve(source, 'test', name)]);
  run(php, [...ini, 'flarum', 'extension:disable', 'fof-sitemap']);
  try {
    const r = await fetch(new URL('/robots.txt', base));
    assert.equal(r.status, 200);
    assert.equal(await r.text(), `User-agent: *\nDisallow: /admin\nDisallow: /api/\n\nSitemap: ${base.origin}/sitemap.xml\n`);
    console.log('F2_ROBOTS_WITHOUT_SITEMAP=PASS');
  } finally { run(php, [...ini, 'flarum', 'extension:enable', 'fof-sitemap']); }
  const manifest = Object.fromEntries(Object.entries(sources).map(([key, path]) => [key, { head: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: path, encoding: 'utf8' }).trim(), dirty: execFileSync('git', ['status', '--porcelain'], { cwd: path, encoding: 'utf8' }).trim() !== '' }]));
  const runtime = JSON.parse(execFileSync(php, [...ini, resolve(source, 'test/f2-installed-manifest.php')], { cwd: app, env, encoding: 'utf8' }));
  writeFileSync(resolve(evidence, 'source-manifest.json'), JSON.stringify({ sources: manifest, runtime, packages: JSON.parse(readFileSync(resolve(app, 'composer.lock'))).packages.map(p => ({ name: p.name, version: p.version, reference: p.source?.reference || p.dist?.reference })), productionMutated: false }, null, 2));
  console.log('F2_MEMBER_HARNESS=PASS');
} finally { server.kill('SIGTERM'); }
