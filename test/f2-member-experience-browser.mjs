import assert from 'node:assert/strict';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const { chromium, expect } = createRequire(process.env.PLAYWRIGHT_PACKAGE_JSON)('@playwright/test');
const fixture = JSON.parse(readFileSync(process.env.F2_AUTH_FILE, 'utf8'));
const evidence = resolve(process.env.F2_EVIDENCE_DIR || '/tmp/f2-member-browser-evidence');
mkdirSync(evidence, { recursive: true });
assert.equal(new URL(fixture.baseUrl).hostname, '127.0.0.1');
const browser = await chromium.launch({ headless: true });
const results = [];
const contexts = [];
async function contextFor(role, options = {}) {
  const context = await browser.newContext(options); contexts.push(context);
  if (role) await context.addCookies([{ name: 'flarum_remember', value: fixture.users[role].token, domain: '127.0.0.1', path: '/' }]);
  return context;
}
async function visit(page, path) {
  const response = await page.goto(fixture.baseUrl + path, { waitUntil: 'networkidle' });
  if (response) assert.equal(response.status(), 200, `HTTP 200 ${path}`);
  else assert.equal(new URL(page.url()).hash, new URL(fixture.baseUrl + path).hash, 'same-document admin navigation reaches the requested hash route');
}
async function record(name, run) {
  await run(); results.push({ name, status: 'PASS' }); console.log(`PASS ${name}`);
  writeFileSync(resolve(evidence, 'browser-results.json'), JSON.stringify(results, null, 2));
}
async function noOverflow(page) {
  const size = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  assert.ok(size.document <= size.viewport + 1, `horizontal overflow ${JSON.stringify(size)}`);
}

try {
  const member = await contextFor('member', { viewport: { width: 1440, height: 900 } });
  const page = await member.newPage();
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  await record('member settings persist both nickname modes after reload', async () => {
    await visit(page, '/settings');
    const section = page.locator('.FlatRateMemberDisplay'); await expect(section).toBeVisible();
    const radios = section.locator('input[type=radio]');
    const setMode = async index => {
      if (await radios.nth(index).isChecked()) return;
      const [response] = await Promise.all([page.waitForResponse(r => r.url().endsWith('/flatrate/member-display') && (r.request().headers()['x-http-method-override'] || r.request().method()) === 'PATCH'), radios.nth(index).click()]);
      assert.equal(response.status(), 200);
      await expect(radios.nth(index)).toBeChecked();
    };
    await setMode(0); await page.reload({ waitUntil: 'networkidle' }); await expect(radios.nth(0)).toBeChecked();
    await expect(section.locator('input[type=text]')).toHaveValue('MasterTech42');
    await setMode(1); await page.reload({ waitUntil: 'networkidle' }); await expect(radios.nth(1)).toBeChecked();
    await expect(section.locator('input[type=text]')).toHaveValue('MasterTech42');
    await page.screenshot({ path: resolve(evidence, 'desktop-identity-settings.png'), fullPage: true });
  });
  await record('owner dashboard and identity navigation', async () => {
    await visit(page, '/u/' + fixture.users.member.username);
    await expect(page.locator('.FlatRateOwnerDashboard')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Manage Community identity', exact: true })).toHaveAttribute('href', '/settings');
    await page.screenshot({ path: resolve(evidence, 'desktop-owner-dashboard.png'), fullPage: true });
  });
  await record('Masquerade profile UI edits and reloads affiliation', async () => {
    await visit(page, '/u/' + fixture.users.member.username + '/masquerade');
    const pane = page.locator('.MasqueradeRoot'); await expect(pane).toBeVisible();
    const select = pane.locator('select').first(); await expect(select).toBeVisible();
    await select.selectOption({ label: 'Ford' });
    const [response] = await Promise.all([page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/masquerade-answers/configure/')), pane.getByRole('button', { name: /save/i }).click()]);
    assert.equal(response.status(), 200);
    await page.reload({ waitUntil: 'networkidle' }); await expect(select).toHaveValue('Ford');
    await select.selectOption({ label: 'Chevrolet' });
    await Promise.all([page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/masquerade-answers/configure/')), pane.getByRole('button', { name: /save/i }).click()]);
  });
  await record('plain upvote UI persists, updates totals, and supports keyboard removal', async () => {
    await visit(page, '/d/' + fixture.discussion);
    const post = page.locator('.PostStream-item[data-id="' + fixture.reply + '"]');
    const vote = post.getByRole('button', { name: 'Upvote', exact: true });
    await expect(vote).toBeEnabled();
    const size = await vote.boundingBox(); assert.ok(size.width >= 44 && size.height >= 44);
    await vote.click(); await expect(post.getByRole('button', { name: 'Remove upvote', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await page.reload({ waitUntil: 'networkidle' });
    const remove = post.getByRole('button', { name: 'Remove upvote', exact: true }); await expect(remove).toHaveAttribute('aria-pressed', 'true');
    await remove.focus(); await expect(remove).toBeFocused(); await page.keyboard.press('Space');
    await expect(vote).toHaveAttribute('aria-pressed', 'false');
    const peer = await contextFor('peer'); const peerPage = await peer.newPage();
    await visit(peerPage, '/d/' + fixture.discussion);
    const first = peerPage.locator('.PostStream-item[data-id="' + fixture.firstPost + '"]');
    await first.getByRole('button', { name: 'Upvote', exact: true }).click();
    await expect(first.getByRole('button', { name: 'Remove upvote', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await peerPage.reload({ waitUntil: 'networkidle' });
    await expect(first.getByRole('button', { name: 'Remove upvote', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await peerPage.screenshot({ path: resolve(evidence, 'desktop-native-upvotes.png'), fullPage: false });
    await visit(peerPage, '/t/chevrolet');
    const card = peerPage.locator('.DiscussionListItem').filter({ hasText: 'Qualification Chevrolet repair discussion' });
    await expect(card.locator('.FlatRateDiscussionListTotal')).toHaveText('1');
    await peerPage.screenshot({ path: resolve(evidence, 'desktop-discussion-vote-total.png'), fullPage: false });
    await visit(peerPage, '/d/' + fixture.discussion);
    await first.getByRole('button', { name: 'Remove upvote', exact: true }).click();
    await expect(first.getByRole('button', { name: 'Upvote', exact: true })).toHaveAttribute('aria-pressed', 'false');
  });
  await record('Direct recipient picker sends and peer reads persisted message', async () => {
    await visit(page, '/messages');
    await page.getByRole('button', { name: 'New message', exact: true }).first().click();
    const modal = page.locator('.StartConversationModal');
    await modal.locator('input.RecipientsInput').fill(fixture.users.peer.username);
    await modal.locator('.Search-results button').filter({ hasText: 'Peer Technician' }).click();
    const text = `Member acceptance DM ${Date.now()}`;
    await modal.locator('textarea').fill(text);
    await modal.locator('button[type=submit]').click();
    await expect(page.locator('.MessagesDirectSurface')).toBeVisible();
    // An existing recipient opens the conversation with the draft preserved.
    // Exercise the visible Send action rather than expecting a second thread.
    const composer = page.locator('.DirectComposer');
    if ((await composer.locator('textarea').inputValue()) === text) {
      await composer.locator('button[type=submit]').click();
    }
    await expect(page.locator('.MessagesDirectSurface')).toContainText(text);
    const conversationPath = new URL(page.url()).pathname;
    assert.match(conversationPath, /\/messages\/direct\/\d+/);
    await page.reload({ waitUntil: 'networkidle' }); await expect(page.locator('.MessagesDirectSurface')).toContainText(text);
    const peer = await contextFor('peer'); const peerPage = await peer.newPage();
    await visit(peerPage, conversationPath); await expect(peerPage.locator('.MessagesDirectSurface')).toContainText(text);
    const outsider = await contextFor('outsider'); const outsiderPage = await outsider.newPage();
    await visit(outsiderPage, conversationPath); await expect(outsiderPage.locator('body')).not.toContainText(text);
    fixture.conversationPath = conversationPath;
    writeFileSync(process.env.F2_AUTH_FILE, JSON.stringify(fixture, null, 2));
    await page.screenshot({ path: resolve(evidence, 'desktop-direct-conversation.png'), fullPage: true });
  });
  for (const width of [320, 390, 393, 844, 1280, 1440]) {
    await record(`responsive navigation and discussion geometry ${width}px`, async () => {
      await page.setViewportSize({ width, height: width === 844 ? 390 : 900 });
      await visit(page, '/'); await noOverflow(page);
      if (width >= 992) await expect(page.locator('.IndexPage-nav .FlatRatePresentationNav')).toBeVisible();
      await visit(page, '/d/' + fixture.discussion); await noOverflow(page);
      await expect(page.locator('.FlatRateAffiliatedBrand').first()).toHaveText('Chevrolet');
      await expect(page.locator('.item-flatrateJobBreakdownTag').first()).toBeVisible();
      await page.screenshot({ path: resolve(evidence, `discussion-${width}.png`), fullPage: width >= 992 });
    });
  }
  await record('guest HTML excludes private profile and discussion content', async () => {
    const guest = await contextFor(null, { viewport: { width: 390, height: 844 } }); const guestPage = await guest.newPage();
    await visit(guestPage, '/d/' + fixture.discussion);
    await expect(guestPage.locator('.FlatRateAffiliatedBrand')).toHaveCount(0);
    await expect(guestPage.locator('body')).not.toContainText('MasterTech42');
    await expect(guestPage.locator('body')).not.toContainText('QUAL_RESTRICTED_PROFILE_VALUE');
    await guestPage.screenshot({ path: resolve(evidence, 'guest-phone-discussion.png'), fullPage: false });
  });
  await record('administrator can inspect native upvote setting and permission', async () => {
    const admin = await contextFor('admin'); const adminPage = await admin.newPage();
    const adminErrors = []; adminPage.on('pageerror', error => adminErrors.push(error.message));
    await visit(adminPage, '/admin#/extension/flatrate-wiki-supabase-oauth');
    await expect(adminPage.getByText('Enable plain upvotes', { exact: true })).toBeVisible();
    await visit(adminPage, '/admin#/permissions');
    await expect(adminPage.getByText('Upvote posts', { exact: true })).toBeVisible();
    assert.deepEqual(adminErrors, [], 'admin bundles initialize without page exceptions');
    await adminPage.screenshot({ path: resolve(evidence, 'admin-upvote-permission.png'), fullPage: false });
  });
  assert.deepEqual(errors, [], 'no browser page exceptions');
  console.log(`F2_MEMBER_BROWSER=PASS SCENARIOS=${results.length}`);
} catch (error) {
  results.push({ name: 'acceptance failure', status: 'FAIL', error: String(error.message) });
  writeFileSync(resolve(evidence, 'browser-results.json'), JSON.stringify(results, null, 2));
  for (const context of contexts) for (const page of context.pages()) await page.screenshot({ path: resolve(evidence, `failure-${contexts.indexOf(context)}-${context.pages().indexOf(page)}.png`) }).catch(() => {});
  throw error;
} finally { await browser.close(); }
