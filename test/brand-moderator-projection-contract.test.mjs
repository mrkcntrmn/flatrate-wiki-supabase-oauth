import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (file) => readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');

test('Brand moderator sync is HMAC-only, route-scoped, disabled by default', () => {
  const extension = read('extend.php');
  const provider = read('src/ServiceProvider.php');
  const controller = read('src/Sso/BrandModeratorSyncController.php');
  assert.match(extension, /flatrate-brand-moderator\.signed_sync_enabled', '0'/);
  assert.match(extension, /post\('\/flatrate-sso\/brand-moderator', 'flatrate-sso\.brand-moderator'/);
  assert.match(provider, /'flatrate-sso\.brand-moderator'/);
  assert.match(controller, /\$this->authenticator->authenticate\(\$request\)/);
  assert.match(controller, /brand_moderator_sync_disabled/);
  assert.match(controller, /\$this->linkedUsers->findBySubject\(\$sub\)/);
  assert.match(controller, /!is_bool\(\$active\)/);
  assert.match(controller, /!is_int\(\$revision\)/);
  assert.match(controller, /\$capability !== 'moderate_live'/);
});

test('Only real Flarum-linked stable account and exact Brand key can be projected', () => {
  const table = read('src/Brand/BrandModeratorProjectionTable.php');
  const projection = read('src/Brand/BrandModeratorProjectionStore.php');
  const migration = read('migrations/2026_10_18_000000_create_brand_moderator_projection.php');
  assert.match(table, /validBrandKey/);
  assert.match(table, /lockForUpdate/);
  assert.match(table, /\$revision <= \(int\) \$existing->revision/);
  assert.match(table, /MAX_SYNC_AGE_SECONDS = 180/);
  assert.match(table, /return false;\s*}\s*\$synced = strtotime/);
  assert.match(projection, /\$capability !== 'moderate_live'/);
  assert.match(migration, /->primary\(\['user_id', 'brand_key'\]/);
  for (const forbidden of ['email', 'auth_token', 'invite_token', 'phone_number', 'nickname']) {
    assert.doesNotMatch(migration, new RegExp(`->(?:string|text|binary)\\('${forbidden}'`));
  }
});
