import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
const { chromium, expect } = createRequire(process.env.PLAYWRIGHT_PACKAGE_JSON)('@playwright/test');
const fixture = JSON.parse(readFileSync(process.env.F2_AUTH_FILE, 'utf8'));
const evidence = resolve(process.env.F2_EVIDENCE_DIR);
assert.equal(new URL(fixture.baseUrl).hostname, '127.0.0.1');
assert.match(fixture.conversationPath || '', /^\/messages\/direct\/\d+$/);
mkdirSync(evidence, { recursive: true });
const serial = process.env.ANDROID_SERIAL;
assert.ok(serial, 'Explicit authorized device serial required');
const adb = (...args) => execFileSync('adb', ['-s', serial, ...args], { encoding: 'utf8' }).trim();
const initial = Object.fromEntries(['accelerometer_rotation', 'user_rotation'].map(k => [k, adb('shell', 'settings', 'get', 'system', k)]));
const results = [];
const browser = await chromium.connectOverCDP(process.env.ANDROID_CDP_URL || 'http://127.0.0.1:9223');
const context = browser.contexts()[0];
// Restrict all interaction to the disposable loopback application.
const page = context.pages().find(p => p.url().startsWith(fixture.baseUrl)) || await context.newPage();
const cdp = await context.newCDPSession(page);
async function touch(locator) { await locator.scrollIntoViewIfNeeded(); const r = await locator.boundingBox(); assert.ok(r); const p = { x: r.x + r.width / 2, y: r.y + r.height / 2 }; await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [p] }); await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); }
const errors = []; page.on('pageerror', e => errors.push(e.message));
async function record(name, fn) { const detail = await fn(); results.push({ name, status: 'PASS', detail }); console.log('PASS ' + name); }
function shot(name) { writeFileSync(resolve(evidence, name + '.png'), execFileSync('adb', ['-s', serial, 'exec-out', 'screencap', '-p'], { maxBuffer: 20 * 1024 * 1024 })); }
async function visit(path) { const response = await page.goto(fixture.baseUrl + path, { waitUntil: 'networkidle' }); assert.equal(response.status(), 200); }
async function geometry() { return page.evaluate(() => ({ width: innerWidth, height: innerHeight, scrollWidth: document.documentElement.scrollWidth, visualHeight: visualViewport.height, scale: visualViewport.scale })); }
try {
  assert.equal(adb('shell', 'getprop', 'ro.product.model'), 'Pixel 5');
  await context.addCookies([{ name: 'flarum_remember', value: fixture.users.member.token, domain: '127.0.0.1', path: '/' }]);
  await record('physical Pixel 5 identity settings and portrait layout', async () => {
    await visit('/settings'); await expect(page.locator('.FlatRateMemberDisplay')).toBeVisible();
    const g = await geometry(); assert.ok(g.width < g.height); assert.ok(g.scrollWidth <= g.width + 1); assert.equal(g.scale, 1);
    await page.locator('.FlatRateMemberDisplay').scrollIntoViewIfNeeded(); shot('pixel5-identity-settings'); return g;
  });
  await record('physical Pixel 5 profile dashboard and affiliation form', async () => {
    await visit('/u/' + fixture.users.member.username); await expect(page.locator('.FlatRateOwnerDashboard')).toBeVisible();
    shot('pixel5-owner-dashboard');
    await visit('/u/' + fixture.users.member.username + '/masquerade');
    await expect(page.locator('.MasqueradeRoot select').first()).toHaveValue('Chevrolet'); shot('pixel5-profile-affiliation');
  });
  await record('physical Pixel 5 plain upvote, discussion total and removal persist', async () => {
    await visit('/d/' + fixture.discussion);
    const post = page.locator('.PostStream-item[data-id="' + fixture.reply + '"]');
    const vote = post.getByRole('button', { name: 'Upvote', exact: true }); await expect(vote).toBeEnabled();
    await touch(vote); await expect(post.getByRole('button', { name: 'Remove upvote', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await page.reload({ waitUntil: 'networkidle' }); await expect(post.getByRole('button', { name: 'Remove upvote', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await post.getByRole('button', { name: 'Remove upvote', exact: true }).scrollIntoViewIfNeeded(); shot('pixel5-native-upvote');
    await visit('/t/chevrolet');
    await expect(page.locator('.DiscussionListItem').filter({ hasText: 'Qualification Chevrolet repair discussion' }).locator('.FlatRateDiscussionListTotal')).toHaveText('1');
    shot('pixel5-discussion-vote-total');
    await visit('/d/' + fixture.discussion); await touch(post.getByRole('button', { name: 'Remove upvote', exact: true }));
    await expect(vote).toHaveAttribute('aria-pressed', 'false');
  });
  await record('physical Pixel 5 Direct keyboard, send and reload', async () => {
    await visit(fixture.conversationPath);
    const composer = page.locator('.DirectComposer'); const input = composer.locator('textarea');
    await expect(input).toBeVisible(); await touch(input);
    const message = 'Pixel acceptance ' + Date.now(); await input.fill(message);
    const keyboard = adb('shell', "dumpsys input_method | grep -E 'mInputShown=|isInputViewShown=|mIsInputViewShown=' | head -n 5"); assert.match(keyboard, /mInputShown=true|isInputViewShown=true|mIsInputViewShown=true/);
    const rect = await composer.locator('button[type=submit]').boundingBox(); const g = await geometry();
    assert.ok(rect && rect.width >= 44 && rect.height >= 44, '44px send target');
    assert.ok(rect.y >= 0 && rect.y + rect.height <= g.visualHeight + 1, 'Send remains above physical keyboard');
    shot('pixel5-direct-keyboard');
    await touch(composer.locator('button[type=submit]'));
    await expect(page.locator('.MessagesDirectSurface')).toContainText(message, { timeout: 15000 });
    adb('shell', 'input', 'keyevent', '111');
    await page.reload({ waitUntil: 'networkidle' }); await expect(page.locator('.MessagesDirectSurface')).toContainText(message);
    shot('pixel5-direct-persisted'); return { ...g, sendTarget: rect };
  });
  await record('physical Pixel 5 landscape has no overflow and reachable composer', async () => {
    adb('shell', 'settings', 'put', 'system', 'accelerometer_rotation', '0'); adb('shell', 'settings', 'put', 'system', 'user_rotation', '1');
    await page.waitForFunction(() => innerWidth > innerHeight);
    const g = await geometry(); assert.ok(g.scrollWidth <= g.width + 1); await expect(page.locator('.DirectComposer textarea')).toBeVisible();
    shot('pixel5-direct-landscape'); return g;
  });
  await record('physical Pixel 5 guest cannot read Direct history', async () => {
    await context.clearCookies({ domain: '127.0.0.1' });
    await visit(fixture.conversationPath); await expect(page.locator('.MessagesDirectSurface')).toHaveCount(0);
    assert.equal(await page.evaluate(() => window.app?.session?.user?.id?.() || null), null);
  });
  assert.deepEqual(errors, []); console.log('F2_PIXEL_ACCEPTANCE=PASS SCENARIOS=' + results.length);
} catch (error) { results.push({ name: 'physical acceptance failure', status: 'FAIL', error: error.message }); shot('pixel5-failure'); throw error;
} finally {
  for (const [key, value] of Object.entries(initial)) adb('shell', 'settings', 'put', 'system', key, value);
  writeFileSync(resolve(evidence, 'pixel-results.json'), JSON.stringify({ device: 'Pixel 5', transport: 'physical USB ADB + Chrome CDP', results, errors }, null, 2));
  await browser.close();
}
