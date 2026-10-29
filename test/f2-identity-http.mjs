import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash, createHmac, randomBytes, randomUUID } from 'node:crypto';

const fixture = JSON.parse(readFileSync(process.env.F2_AUTH_FILE, 'utf8'));
const install = JSON.parse(readFileSync(process.env.F2_INSTALL_FILE, 'utf8'));
const base = new URL(fixture.baseUrl);
assert.equal(base.hostname, '127.0.0.1', 'identity acceptance must use the disposable loopback forum');
let checks = 0;
function check(ok, name) { assert.ok(ok, name); checks++; console.log(`PASS ${name}`); }
function signed(path, payload) {
  const body = JSON.stringify(payload);
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = randomBytes(24).toString('base64url');
  // Match the root application's established bridge contract: Flarum strips
  // its /api mount prefix before the controller verifies the signature.
  const canonicalPath = path.startsWith('/api/') ? path.slice(4) : path;
  const canonical = [timestamp, nonce, 'POST', canonicalPath, createHash('sha256').update(body).digest('hex')].join('\n');
  return { method: 'POST', redirect: 'manual', body, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-FlatRate-Timestamp': timestamp, 'X-FlatRate-Nonce': nonce, 'X-FlatRate-Signature': createHmac('sha256', fixture.ssoSecret).update(canonical).digest('hex') } };
}
const sub = randomUUID();
const identity = { sub, email: `f2-identity-${sub}@example.test`, email_verified: true };
const provisionPath = '/api/flatrate-sso/provision';
let response = await fetch(base + provisionPath.slice(1), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(identity) });
check(response.status === 401, 'unsigned provisioning denied');
const request = signed(provisionPath, identity);
response = await fetch(new URL(provisionPath, base), request);
const provisioned = await response.json();
check(response.status === 200 && Number.isInteger(provisioned.user_id), `verified signed subject provisions through real Flarum registration (status ${response.status}, code ${provisioned.error || provisioned.errors?.[0]?.code || 'none'})`);
response = await fetch(new URL(provisionPath, base), request);
check(response.status === 401, 'authenticated nonce replay denied');
response = await fetch(new URL(provisionPath, base), signed(provisionPath, identity));
check(response.status === 200 && (await response.json()).user_id === provisioned.user_id, 'repeat provisioning preserves existing mapping');
response = await fetch(new URL(provisionPath, base), signed(provisionPath, { ...identity, sub: randomUUID() }));
check(response.status === 409, 'shared email does not merge unrelated subjects');
response = await fetch(new URL(provisionPath, base), signed(provisionPath, { ...identity, sub: randomUUID(), email: `unverified-${sub}@example.test`, email_verified: false }));
check(response.status >= 400 && response.status < 500, 'unverified email cannot provision');
const ticketPath = '/api/flatrate-sso/ticket';
response = await fetch(new URL(ticketPath, base), signed(ticketPath, { ...identity, return_to: '/settings' }));
const ticket = await response.json();
check(response.status === 200 && ticket.expires_in === 45 && ticket.entry_path?.startsWith('/auth/flatrate/session?ticket='), 'one-time ticket issued with bounded lifetime');
response = await fetch(new URL(ticket.entry_path, base), { redirect: 'manual' });
check(response.status === 302 && new URL(response.headers.get('location'), base).pathname === '/settings', 'ticket establishes session at the allowed return path');
check(response.headers.get('cache-control')?.includes('no-store') && response.headers.get('referrer-policy') === 'no-referrer', 'ticket response protects cache and referrer');
check(response.headers.getSetCookie().some(cookie => cookie.startsWith('flarum_remember=')), 'ticket issues real forum authentication cookie');
response = await fetch(new URL(ticket.entry_path, base), { redirect: 'manual' });
check(response.status === 401, 'consumed ticket replay denied');
response = await fetch(new URL(ticketPath, base), signed(ticketPath, { ...identity, return_to: '//outside.example.test/' }));
check(response.status === 400, 'external return path denied');
response = await fetch(new URL('/api/token', base), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identification: install.adminUser.username, password: install.adminUser.password }) });
check(response.status === 200 && typeof (await response.json()).token === 'string', 'native administrator recovery login works');
response = await fetch(new URL('/api/token', base), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identification: install.adminUser.username, password: 'incorrect-test-password' }) });
check(response.status >= 400 && response.status < 500, 'invalid administrator password rejected');
response = await fetch(new URL('/api/token', base), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identification: fixture.users.member.username, password: 'irrelevant-native-password' }) });
check(response.status === 403, 'ordinary member native password login remains closed');
const guestSession = await fetch(base);
const guestCookie = guestSession.headers.getSetCookie().map(cookie => cookie.split(';')[0]).join('; ');
response = await fetch(new URL('/api/users', base), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Cookie': guestCookie, 'X-CSRF-Token': guestSession.headers.get('x-csrf-token') || '' }, body: JSON.stringify({ data: { type: 'users', attributes: { username: 'fabricated_registration', email: 'fabricated@example.test', password: 'fabricated-test-password', token: 'fabricated' } } }) });
check(response.status === 403, `fabricated native registration token denied with valid guest CSRF (status ${response.status})`);
console.log(`F2_IDENTITY_HTTP=PASS CHECKS=${checks}`);
