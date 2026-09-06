<?php

/**
 * FORUM-EMAIL-002 — pure PHP policy gates for the reserved internal email namespace.
 *
 * Executable without a Flarum database. Explicitly exits nonzero on failure
 * (does not rely on PHP assert.ini being enabled).
 */

require __DIR__.'/../src/Identity/ForumEmailPolicy.php';

use FlatRate\SupabaseOAuth\Identity\ForumEmailPolicy;

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

expect(ForumEmailPolicy::isInternal('forum-abc@users.flatrate.wiki') === true, 'forum-abc@users.flatrate.wiki is internal');
expect(ForumEmailPolicy::isDeliverable('forum-abc@users.flatrate.wiki') === false, 'forum-abc@users.flatrate.wiki is not deliverable');

expect(ForumEmailPolicy::isInternal('FORUM-ABC@USERS.FLATRATE.WIKI') === true, 'case-insensitive internal domain');
expect(ForumEmailPolicy::isDeliverable('FORUM-ABC@USERS.FLATRATE.WIKI') === false, 'case-insensitive not deliverable');

expect(ForumEmailPolicy::isInternal('real@example.com') === false, 'real@example.com is not internal');
expect(ForumEmailPolicy::isDeliverable('real@example.com') === true, 'real@example.com is deliverable');

expect(ForumEmailPolicy::isInternal('user@users.flatrate.wiki.attacker.example') === false, 'suffix-attack domain is not internal');
expect(ForumEmailPolicy::isInternal('user@notusers.flatrate.wiki') === false, 'prefix-attack domain is not internal');

expect(ForumEmailPolicy::isDeliverable('') === false, 'blank is not deliverable');
expect(ForumEmailPolicy::isDeliverable('   ') === false, 'whitespace is not deliverable');
expect(ForumEmailPolicy::isDeliverable('not-an-email') === false, 'malformed address is not deliverable');
expect(ForumEmailPolicy::isDeliverable('@users.flatrate.wiki') === false, 'missing local-part is not deliverable');

expect(
    ForumEmailPolicy::canPromote('forum-abc@users.flatrate.wiki', 'confirmed@example.com', true) === true,
    'internal -> confirmed real promotes'
);
expect(
    ForumEmailPolicy::canPromote('forum-abc@users.flatrate.wiki', 'confirmed@example.com', false) === false,
    'internal -> unverified real blocked'
);
expect(
    ForumEmailPolicy::canPromote('forum-abc@users.flatrate.wiki', 'forum-def@users.flatrate.wiki', true) === false,
    'internal -> internal blocked'
);
expect(
    ForumEmailPolicy::canPromote('real@example.com', 'forum-abc@users.flatrate.wiki', true) === false,
    'real -> internal blocked'
);
expect(
    ForumEmailPolicy::canPromote('real-a@example.com', 'real-b@example.com', true) === false,
    'real A -> real B blocked'
);
expect(
    ForumEmailPolicy::canPromote('real-a@example.com', 'real-a@example.com', true) === false,
    'real A -> real A blocked'
);
expect(
    ForumEmailPolicy::canPromote('forum-abc@users.flatrate.wiki', '', true) === false,
    'blank incoming blocked'
);
expect(
    ForumEmailPolicy::canPromote('forum-abc@users.flatrate.wiki', 'not-an-email', true) === false,
    'malformed incoming blocked'
);

expect(
    ForumEmailPolicy::isPreservablePromotionRace('forum-abc@users.flatrate.wiki', true) === true,
    'internal + ownership collision is preservable race'
);
expect(
    ForumEmailPolicy::isPreservablePromotionRace('forum-abc@users.flatrate.wiki', false) === false,
    'internal without ownership collision is not preservable'
);
expect(
    ForumEmailPolicy::isPreservablePromotionRace('real@example.com', true) === false,
    'real persisted email is not a preservable promotion race'
);
expect(
    ForumEmailPolicy::isPreservablePromotionRace('', true) === false,
    'blank persisted email is not a preservable promotion race'
);

if ($failures > 0) {
    fwrite(STDERR, "forum-email-policy failures: {$failures}\n");
    exit(1);
}

fwrite(STDERR, "forum-email-policy: all checks passed\n");
exit(0);
