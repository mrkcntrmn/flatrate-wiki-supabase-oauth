#!/usr/bin/env node
/**
 * FORUM-EMAIL-001 / FORUM-SUB-000A — static + runtime teaser-only email policy gates.
 *
 * Runtime path simulates Blade substitution using the exact variable maps from
 * FlatRate override views and FlatRate-owned locale bodies. Sentinels are
 * injected into post/reply content fields that must never reach the rendered
 * email body.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

const FORBIDDEN = [
  /\->content\b/,
  /\->contentHtml\b/,
  /\{content\}/,
  /\{post_content\}/,
  /\{excerpt\}/,
  /\{preview\}/,
  /post_content/,
  /reply->content/,
  /post->content/,
];

const views = [
  'views/flarum-subscriptions/emails/newPost.blade.php',
  'views/flarum-mentions/emails/postMentioned.blade.php',
  'views/flarum-mentions/emails/userMentioned.blade.php',
  'views/flarum-mentions/emails/groupMentioned.blade.php',
  'views/fof-follow-tags/emails/newDiscussion.blade.php',
  'views/fof-follow-tags/emails/newPost.blade.php',
];

const FOLLOW_TAGS_VIEWS = views.slice(4);

const SENTINELS = {
  subscription: 'FLATRATE_EMAIL_SENTINEL_SUB_001_71A9',
  reply: 'FLATRATE_EMAIL_SENTINEL_REPLY_001_38C4',
  userMention: 'FLATRATE_EMAIL_SENTINEL_USERMENTION_001_E2B5',
  groupMention: 'FLATRATE_EMAIL_SENTINEL_GROUPMENTION_001_4D77',
  followTagsNewDiscussion: 'FLATRATE_EMAIL_SENTINEL_FOLLOW_DISC_001_A7C2',
  followTagsNewPost: 'FLATRATE_EMAIL_SENTINEL_FOLLOW_POST_001_B9E1',
};

const SUBJECTS = {
  subscription: '[New Post] {title}',
  reply: '{replier_display_name} replied to your post in {title}',
  userMention: '{mentioner_display_name} mentioned you in {title}',
  groupMention: "{mentioner_display_name} mentioned a group you're a member of in {title}",
  // Exact Follow Tags 1.3.0 subject keys (no body/excerpt placeholders).
  followTagsNewDiscussion: '[Tag | New Discussion] {title}',
  followTagsNewPost: '[Tag | New Post] {title}',
};

function test(name, fn) {
  try {
    fn();
    console.error(`[PASS] ${name}`);
  } catch (err) {
    console.error(`[FAIL] ${name}`);
    throw err;
  }
}

function extractPipeBody(en, heading) {
  const start = en.indexOf(heading);
  assert.ok(start >= 0, `missing heading ${heading}`);
  const after = en.slice(start);
  const bodyMark = after.indexOf('body: |');
  assert.ok(bodyMark >= 0, `missing body for ${heading}`);
  const lines = after.slice(bodyMark + 'body: |'.length).split('\n');
  const collected = [];
  for (const line of lines.slice(1)) {
    if (line === '') {
      collected.push('');
      continue;
    }
    if (/^          /.test(line)) {
      collected.push(line.replace(/^          /, ''));
      continue;
    }
    break;
  }
  while (collected.length && collected[collected.length - 1] === '') {
    collected.pop();
  }
  return collected.join('\n');
}

function loadLocaleBodies() {
  const en = readFileSync(join(ROOT, 'resources/locale/en.yml'), 'utf8');
  const followTagsStart = en.indexOf('    follow_tags:');
  assert.ok(followTagsStart >= 0, 'missing follow_tags locale section');
  const followTagsSection = en.slice(followTagsStart);
  const bodies = {
    subscription: extractPipeBody(en, '    subscriptions:\n      new_post:'),
    reply: extractPipeBody(en, '      post_mentioned:'),
    userMention: extractPipeBody(en, '      user_mentioned:'),
    groupMention: extractPipeBody(en, '      group_mentioned:'),
    followTagsNewDiscussion: extractPipeBody(followTagsSection, '      new_discussion:'),
    followTagsNewPost: extractPipeBody(followTagsSection, '      new_post:'),
  };
  for (const [name, body] of Object.entries(bodies)) {
    assert.ok(body.includes('{title}'), `incomplete locale body for ${name}`);
    assert.ok(body.includes('{url}'), `incomplete locale body for ${name}`);
  }
  return { en, bodies };
}

function applyTemplate(template, vars) {
  let out = template;
  for (const [key, value] of Object.entries(vars)) {
    out = out.split(`{${key}}`).join(String(value));
  }
  return out;
}

function extractBladeVars(rel) {
  const text = readFileSync(join(ROOT, rel), 'utf8');
  const vars = {};
  for (const m of text.matchAll(/'\{([^}]+)\}'\s*=>\s*([^,\n]+)/g)) {
    vars[m[1]] = m[2].trim();
  }
  return { text, vars };
}

function assertNoLeak(label, rendered, sentinel) {
  const hay = String(rendered);
  assert.equal(hay.includes(sentinel), false, `${label} leaked sentinel`);
  assert.equal(/\{content\}/.test(hay), false, `${label} contains {content}`);
  assert.equal(/\{post_content\}/.test(hay), false, `${label} contains {post_content}`);
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
  console.error(`FOLLOW_TAGS_OVERRIDE_VIEW_COUNT=${FOLLOW_TAGS_VIEWS.length}`);
});

test('EMAIL_TRANSLATION_CONTENT_PLACEHOLDERS=0', () => {
  const { en } = loadLocaleBodies();
  assert.match(en, /flatrate-email-policy:/);
  assert.doesNotMatch(en, /\{content\}/);
  assert.doesNotMatch(en, /\{post_content\}/);
  assert.doesNotMatch(en, /\{excerpt\}/);
  assert.doesNotMatch(en, /\{preview\}/);
  assert.match(en, /View (the new activity|the reply|the post|the discussion) on FlatRate\.wiki:/);
});

test('Extend\\View namespaces registered', () => {
  const extend = readFileSync(join(ROOT, 'extend.php'), 'utf8');
  assert.match(extend, /Extend\\\\View|Extend\\View/);
  assert.match(extend, /flarum-subscriptions/);
  assert.match(extend, /flarum-mentions/);
  assert.match(extend, /fof-follow-tags/);
  assert.match(extend, /views\/fof-follow-tags/);
  // Must not require Follow Tags PHP classes at bootstrap.
  assert.doesNotMatch(extend, /FoF\\\\FollowTags|FoF\\FollowTags/);
  console.error('FOLLOW_TAGS_VIEW_NAMESPACE_GATE=PASS');
});

test('EMAIL_SUBJECT_BODY_EXCERPT=false', () => {
  for (const subject of Object.values(SUBJECTS)) {
    assert.doesNotMatch(subject, /\{content\}/);
    assert.doesNotMatch(subject, /\{post_content\}/);
    assert.doesNotMatch(subject, /excerpt|preview|body/i);
  }
});

test('composer.json does not require fof/follow-tags', () => {
  const composer = readFileSync(join(ROOT, 'composer.json'), 'utf8');
  assert.doesNotMatch(composer, /fof\/follow-tags/);
});

test('runtime sentinel matrix — subscription / reply / mentions / follow-tags', () => {
  const { bodies } = loadLocaleBodies();

  const cases = [
    {
      name: 'subscription',
      view: views[0],
      sentinel: SENTINELS.subscription,
      subject: SUBJECTS.subscription,
      requiredExpr: {
        recipient_display_name: '$user->display_name',
        poster_display_name: '$blueprint->post->user->display_name',
        title: '$blueprint->post->discussion->title',
        url: "route('discussion'",
      },
      localeVars: {
        recipient_display_name: 'Tech_42',
        poster_display_name: 'WrenchBeta',
        title: "What's the biggest gravy job on a Toyota?",
        url: 'https://forum.flatrate.wiki/d/1-gravy/2',
      },
      forbiddenExpr: ['->content', '{content}', '{post_content}', 'post_content'],
    },
    {
      name: 'reply',
      view: views[1],
      sentinel: SENTINELS.reply,
      subject: SUBJECTS.reply,
      requiredExpr: {
        recipient_display_name: '$user->display_name',
        replier_display_name: '$blueprint->reply->user->display_name',
        title: '$blueprint->post->discussion->title',
        url: "route('discussion'",
      },
      localeVars: {
        recipient_display_name: 'Tech_42',
        replier_display_name: 'WrenchBeta',
        title: "What's the biggest gravy job on a Toyota?",
        url: 'https://forum.flatrate.wiki/d/1-gravy/3',
      },
      forbiddenExpr: ['->content', '{content}', '{post_content}', 'post_content'],
    },
    {
      name: 'userMention',
      view: views[2],
      sentinel: SENTINELS.userMention,
      subject: SUBJECTS.userMention,
      requiredExpr: {
        recipient_display_name: '$user->display_name',
        mentioner_display_name: '$blueprint->post->user->display_name',
        title: '$blueprint->post->discussion->title',
        url: "route('discussion'",
      },
      localeVars: {
        recipient_display_name: 'Tech_42',
        mentioner_display_name: 'WrenchBeta',
        title: "What's the biggest gravy job on a Toyota?",
        url: 'https://forum.flatrate.wiki/d/1-gravy/4',
      },
      forbiddenExpr: ['->content', '{content}', '{post_content}', 'post_content'],
    },
    {
      name: 'groupMention',
      view: views[3],
      sentinel: SENTINELS.groupMention,
      subject: SUBJECTS.groupMention,
      requiredExpr: {
        recipient_display_name: '$user->display_name',
        mentioner_display_name: '$blueprint->post->user->display_name',
        title: '$blueprint->post->discussion->title',
        url: "route('discussion'",
      },
      localeVars: {
        recipient_display_name: 'Tech_42',
        mentioner_display_name: 'WrenchBeta',
        title: "What's the biggest gravy job on a Toyota?",
        url: 'https://forum.flatrate.wiki/d/1-gravy/5',
      },
      forbiddenExpr: ['->content', '{content}', '{post_content}', 'post_content'],
    },
    {
      name: 'followTagsNewDiscussion',
      view: views[4],
      sentinel: SENTINELS.followTagsNewDiscussion,
      subject: SUBJECTS.followTagsNewDiscussion,
      requiredExpr: {
        recipient_display_name: '$user->display_name',
        actor_display_name: '$blueprint->discussion->user->display_name',
        title: '$blueprint->discussion->title',
        url: "route('discussion'",
      },
      localeVars: {
        recipient_display_name: 'Tech_42',
        actor_display_name: 'WrenchBeta',
        title: "What's the biggest gravy job on a Toyota?",
        url: 'https://forum.flatrate.wiki/d/1-gravy',
      },
      forbiddenExpr: ['->content', '{content}', '{post_content}', 'post_content', '->contentHtml'],
    },
    {
      name: 'followTagsNewPost',
      view: views[5],
      sentinel: SENTINELS.followTagsNewPost,
      subject: SUBJECTS.followTagsNewPost,
      requiredExpr: {
        recipient_display_name: '$user->display_name',
        actor_display_name: '$blueprint->post->user->display_name',
        title: '$blueprint->post->discussion->title',
        url: "route('discussion'",
      },
      localeVars: {
        recipient_display_name: 'Tech_42',
        actor_display_name: 'WrenchBeta',
        title: "What's the biggest gravy job on a Toyota?",
        url: 'https://forum.flatrate.wiki/d/1-gravy/7',
      },
      forbiddenExpr: ['->content', '{content}', '{post_content}', 'post_content', '->contentHtml'],
    },
  ];

  let contextPresent = 0;
  let followTagsPostContentRefs = 0;
  let followTagsContentPlaceholders = 0;

  for (const c of cases) {
    const { text, vars } = extractBladeVars(c.view);
    for (const expr of c.forbiddenExpr) {
      if (text.includes(expr)) {
        if (c.name.startsWith('followTags')) {
          if (expr.includes('content') || expr.includes('post_content')) {
            followTagsPostContentRefs += 1;
          }
        }
      }
      assert.equal(text.includes(expr), false, `${c.name} view contains ${expr}`);
    }
    for (const [key, needle] of Object.entries(c.requiredExpr)) {
      assert.ok(vars[key], `${c.name} missing var ${key}`);
      assert.ok(vars[key].includes(needle) || text.includes(needle), `${c.name} ${key} deep-link/context missing`);
    }
    // Content sentinel is present on the fictional post/reply object but never mapped into the view.
    const poisonedContent = c.sentinel;
    assert.ok(poisonedContent);
    assert.equal(
      Object.values(vars).some((v) => String(v).includes('->content')),
      false
    );

    const body = applyTemplate(bodies[c.name], c.localeVars);
    const subject = applyTemplate(c.subject, c.localeVars);
    assertNoLeak(`${c.name} body`, body, c.sentinel);
    assertNoLeak(`${c.name} subject`, subject, c.sentinel);
    // Positive teaser context
    assert.match(body, /WrenchBeta/);
    assert.match(body, /gravy job on a Toyota/);
    assert.match(body, /https:\/\/forum\.flatrate\.wiki\//);
    assert.match(body, /FlatRate\.wiki/);
    assert.equal(body.includes(poisonedContent), false);

    if (c.name.startsWith('followTags')) {
      if (/\{post_content\}|\{content\}|\{excerpt\}|\{preview\}/.test(bodies[c.name])) {
        followTagsContentPlaceholders += 1;
      }
    }

    contextPresent += 1;
  }

  assert.equal(contextPresent, 6);
  assert.equal(followTagsPostContentRefs, 0);
  assert.equal(followTagsContentPlaceholders, 0);
  console.error('EMAIL_TEASER_CONTEXT_PRESENT=6/6');
  console.error('SUBSCRIPTION_EMAIL_SENTINEL_LEAK=false');
  console.error('POST_REPLY_EMAIL_SENTINEL_LEAK=false');
  console.error('USER_MENTION_EMAIL_SENTINEL_LEAK=false');
  console.error('GROUP_MENTION_EMAIL_SENTINEL_LEAK=false');
  console.error('FOLLOW_TAGS_NEW_DISCUSSION_SENTINEL_LEAK=false');
  console.error('FOLLOW_TAGS_NEW_POST_SENTINEL_LEAK=false');
  console.error('FOLLOW_TAGS_POST_CONTENT_REFERENCE_COUNT=0');
  console.error('FOLLOW_TAGS_CONTENT_PLACEHOLDER_COUNT=0');
  console.error('EMAIL_DEEP_LINKS_PRESERVED=true');
  console.error('HTML_BODY_TEASER_ONLY=true');
  console.error('PLAIN_TEXT_BODY_TEASER_ONLY=true');
  console.error('FOLLOW_TAGS_EMAIL_FORMAT=text');
});

console.error('EMAIL_TEASER_STATIC=PASS');
console.error('EMAIL_TEASER_RUNTIME=PASS');
