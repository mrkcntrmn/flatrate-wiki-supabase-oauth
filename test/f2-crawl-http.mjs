import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const fixture = JSON.parse(readFileSync(process.env.F2_AUTH_FILE, 'utf8'));
const base = fixture.baseUrl; assert.equal(new URL(base).hostname, '127.0.0.1');
let checks = 0;
function check(ok, name) { assert.ok(ok, name); checks++; console.log('PASS ' + name); }
async function get(path) { const r = await fetch(base + path); check(r.status === 200, path + ' HTTP 200'); return r.text(); }
const robots = await get('/robots.txt');
check(robots.includes('Sitemap: ' + base + '/sitemap.xml'), 'one combined-stack robots owner publishes same-origin sitemap');
check(robots.includes('Disallow: /api') && robots.includes('Disallow: /admin'), 'API and admin excluded from crawling');
const index = await get('/sitemap.xml'); check(index.includes('<sitemapindex'), 'actual sitemap index generated');
const files = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]); let xml = '';
for (const url of files) { assert.equal(new URL(url).origin, base); xml += await get(new URL(url).pathname); }
check(xml.includes('/d/' + fixture.discussion + '-'), 'public discussion included in sitemap');
check(!xml.includes('/d/' + fixture.privateDiscussion + '-') && !xml.includes('QUAL_PRIVATE'), 'private discussion excluded from sitemap');
check(!xml.includes('/u/'), 'member profiles excluded by configured sitemap policy');
for (const path of ['/t/chevrolet', '/d/' + fixture.discussion]) {
  const html = await get(path);
  const canonicals = [...html.matchAll(/<link\b[^>]*rel=["']canonical["'][^>]*>/gi)];
  check(canonicals.length === 1, path + ' has exactly one canonical');
  check(!html.includes('QUAL_RESTRICTED_PROFILE_VALUE') && !html.includes('QUAL_PRIVATE_POST_SHOULD_NEVER_APPEAR'), path + ' public HTML has no private sentinels');
}
const redirect = await fetch(base + '/community', { redirect: 'manual' });
check([301, 302, 308].includes(redirect.status) && new URL(redirect.headers.get('location'), base).pathname === '/t/start-here', 'legacy community route redirects to Start Here');
console.log('F2_CRAWL_HTTP=PASS CHECKS=' + checks);
