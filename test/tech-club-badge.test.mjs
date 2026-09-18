import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const text = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('TECH CLUB badge is driven by author group membership', async () => {
  const bundle = await text('js/dist/tech-club-badge.js');

  assert.match(bundle, /flatrate-wiki-tech-club-badge/);
  assert.match(bundle, /techClubGroupName\s*=\s*'TECH CLUB'/);
  assert.match(bundle, /typeof user\.groups !== 'function'/);
  assert.match(bundle, /groupName\(group\)\.toUpperCase\(\) === techClubGroupName/);
  assert.match(bundle, /post\.user\(\)/);
  assert.match(bundle, /items\.add\('flatrateTechClubBadge', renderBadge\(\), -6\)/);
  assert.match(bundle, /TECH CLUB 🧼/);
});

test('TECH CLUB badge uses scoped white-on-pink presentation', async () => {
  const css = await text('resources/less/tech-club-badge.less');

  assert.match(css, /\.Post-header\s*>\s*ul\s*>\s*\.item-flatrateTechClubBadge/);
  assert.match(css, /\.FlatRateTechClubBadge\s*\{/);
  assert.match(css, /background:\s*#d63384;/);
  assert.match(css, /color:\s*#ffffff;/);
  assert.match(css, /border-radius:\s*999px;/);
  assert.doesNotMatch(css, /\.TagLabel\s*\{/);
});

test('TECH CLUB badge assets are registered independently', async () => {
  const extendPhp = await text('extend.php');

  assert.match(extendPhp, /resources\/less\/tech-club-badge\.less/);
  assert.match(extendPhp, /js\/dist\/tech-club-badge\.js/);
});
