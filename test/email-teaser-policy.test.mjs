#!/usr/bin/env node
/**
 * Static teaser-only email policy gates for FlatRate forum notification overrides.
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

const FORBIDDEN = [
  /->content\b/,
  /\{content\}/,
  /reply->content/,
  /post->content/,
];

const views = [
  'views/flarum-subscriptions/emails/newPost.blade.php',
  'views/flarum-mentions/emails/postMentioned.blade.php',
  'views/flarum-mentions/emails/userMentioned.blade.php',
  'views/flarum-mentions/emails/groupMentioned.blade.php',
];

function test(name, fn) {
  try {
    fn();
    console.error(`[PASS] ${name}`);
  } catch (err) {
    console.error(`[FAIL] ${name}`);
    throw err;
  }
}

test('FORUM_EMAIL_VIEW_CONTENT_REFERENCE_COUNT=0', () => {
  let hits = 0;
  for (const rel of views) {
    const text = readFileSync(join(ROOT, rel), 'utf8');
    for (const re of FORBIDDEN) {
      if (re.test(text)) {
        hits += 1;
        console.error('forbidden in', rel, re);
      }
    }
    assert.match(text, /flatrate-email-policy\.email\./);
  }
  assert.equal(hits, 0);
});

test('teaser translations present and content-free', () => {
  const en = readFileSync(join(ROOT, 'resources/locale/en.yml'), 'utf8');
  assert.match(en, /flatrate-email-policy:/);
  assert.doesNotMatch(en, /\{content\}/);
  assert.match(en, /Visit FlatRate\.wiki/);
});

test('Extend\\View namespaces registered', () => {
  const extend = readFileSync(join(ROOT, 'extend.php'), 'utf8');
  assert.match(extend, /Extend\\\\View|Extend\\View/);
  assert.match(extend, /flarum-subscriptions/);
  assert.match(extend, /flarum-mentions/);
});

console.error('EMAIL_TEASER_STATIC=PASS');
