<?php

declare(strict_types=1);

$flarumDir = $argv[1] ?? '';
$outFile = $argv[2] ?? '';
if ($flarumDir === '' || $outFile === '' || !is_file($flarumDir.'/site.php')) {
    fwrite(STDERR, "usage: php seed.php <flarum-dir> <seed.json>\n");
    exit(1);
}

$site = require $flarumDir.'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();

/** @var \Illuminate\Database\ConnectionInterface $db */
$db = $container->make('flarum.db');
$schema = $db->getSchemaBuilder();
$now = date('Y-m-d H:i:s');

function existingColumns($schema, string $table): array
{
    return $schema->getColumnListing($table);
}

function filterRow($schema, string $table, array $row): array
{
    $cols = existingColumns($schema, $table);

    return array_intersect_key($row, array_flip($cols));
}

function rememberToken($db, $schema, int $userId, string $now): string
{
    $token = bin2hex(random_bytes(20));
    $db->table('access_tokens')->insert(filterRow($schema, 'access_tokens', [
        'token' => $token,
        'user_id' => $userId,
        'last_activity_at' => $now,
        'created_at' => $now,
        'type' => 'session_remember',
        'title' => null,
        'last_ip_address' => '127.0.0.1',
        'last_user_agent' => 'FlatRateSPAHarness',
    ]));

    return $token;
}

function upsertUser($db, $schema, array $row): int
{
    $existing = $db->table('users')->where('username', $row['username'])->first();
    $filtered = filterRow($schema, 'users', $row);
    if ($existing) {
        $db->table('users')->where('id', $existing->id)->update($filtered);

        return (int) $existing->id;
    }

    return (int) $db->table('users')->insertGetId($filtered);
}

$admin = $db->table('users')->where('username', 'admin')->first();
if (!$admin) {
    fwrite(STDERR, "admin user missing\n");
    exit(1);
}

$db->table('group_permission')->insertOrIgnore([
    ['group_id' => 3, 'permission' => 'user.editOwnNickname'],
]);

$grandId = upsertUser($db, $schema, [
    'username' => 'tech_a1b2c3d4',
    'email' => 'grand@example.com',
    'is_email_confirmed' => 1,
    'password' => password_hash('HarnessPass1', PASSWORD_BCRYPT),
    'nickname' => 'tech_harness20031',
    'joined_at' => $now,
    'last_seen_at' => $now,
    'discussion_count' => 1,
    'comment_count' => 1,
]);
$db->table('group_user')->insertOrIgnore(['user_id' => $grandId, 'group_id' => 3]);
$db->table('flatrate_member_profiles')->updateOrInsert(
    ['user_id' => $grandId],
    filterRow($schema, 'flatrate_member_profiles', [
        'user_id' => $grandId,
        'member_number' => $grandId,
        'display_mode' => 'custom',
        'custom_nickname' => 'tech_harness20031',
        'custom_nickname_origin' => 'grandfathered',
        'assigned_at' => $now,
        'updated_at' => $now,
    ])
);

$newId = upsertUser($db, $schema, [
    'username' => 'tech_b2c3d4e5',
    'email' => 'newmember@example.com',
    'is_email_confirmed' => 1,
    'password' => password_hash('HarnessPass1', PASSWORD_BCRYPT),
    'nickname' => 'tech_#0',
    'joined_at' => $now,
    'last_seen_at' => $now,
    'discussion_count' => 0,
    'comment_count' => 0,
]);
$db->table('users')->where('id', $newId)->update(['nickname' => 'tech_#'.$newId]);
$db->table('group_user')->insertOrIgnore(['user_id' => $newId, 'group_id' => 3]);
$db->table('flatrate_member_profiles')->updateOrInsert(
    ['user_id' => $newId],
    filterRow($schema, 'flatrate_member_profiles', [
        'user_id' => $newId,
        'member_number' => $newId,
        'display_mode' => 'member_number',
        'custom_nickname' => null,
        'custom_nickname_origin' => null,
        'assigned_at' => $now,
        'updated_at' => $now,
    ])
);

$tag = $db->table('tags')->where('slug', 'job-breakdown')->first();
$tagId = $tag
    ? (int) $tag->id
    : (int) $db->table('tags')->insertGetId(filterRow($schema, 'tags', [
        'name' => 'Job Breakdown',
        'slug' => 'job-breakdown',
        'description' => 'Harness tag',
        'color' => '#111827',
        'position' => 0,
        'parent_id' => null,
        'is_restricted' => 0,
        'is_hidden' => 0,
        'discussion_count' => 1,
        'last_posted_at' => $now,
        'last_posted_user_id' => $grandId,
        'icon' => null,
        'created_at' => $now,
    ]));

$discussion = $db->table('discussions')->where('slug', 'harness-discussion')->first();
$discussionId = $discussion
    ? (int) $discussion->id
    : (int) $db->table('discussions')->insertGetId(filterRow($schema, 'discussions', [
        'title' => 'Harness discussion',
        'comment_count' => 1,
        'participant_count' => 1,
        'post_number_index' => 1,
        'created_at' => $now,
        'user_id' => $grandId,
        'first_post_id' => null,
        'last_posted_at' => $now,
        'last_posted_user_id' => $grandId,
        'last_post_id' => null,
        'last_post_number' => 1,
        'hidden_at' => null,
        'hidden_user_id' => null,
        'slug' => 'harness-discussion',
        'is_private' => 0,
        'is_locked' => 0,
        'is_sticky' => 0,
    ]));

$post = $db->table('posts')->where('discussion_id', $discussionId)->where('number', 1)->first();
$postId = $post
    ? (int) $post->id
    : (int) $db->table('posts')->insertGetId(filterRow($schema, 'posts', [
        'discussion_id' => $discussionId,
        'number' => 1,
        'created_at' => $now,
        'user_id' => $grandId,
        'type' => 'comment',
        'content' => '<t><p>Harness post body for Job Breakdown rendering.</p></t>',
        'edited_at' => null,
        'hidden_at' => null,
        'ip_address' => '127.0.0.1',
        'is_private' => 0,
    ]));

$db->table('discussions')->where('id', $discussionId)->update(filterRow($schema, 'discussions', [
    'first_post_id' => $postId,
    'last_post_id' => $postId,
]));
if ($schema->hasTable('discussion_tag')) {
    $db->table('discussion_tag')->insertOrIgnore([
        'discussion_id' => $discussionId,
        'tag_id' => $tagId,
    ]);
}
if ($schema->hasTable('discussion_user')) {
    $db->table('discussion_user')->insertOrIgnore(filterRow($schema, 'discussion_user', [
        'user_id' => $grandId,
        'discussion_id' => $discussionId,
        'last_read_at' => $now,
        'last_read_post_number' => 1,
    ]));
}

$seed = [
    'adminUserId' => (int) $admin->id,
    'adminToken' => rememberToken($db, $schema, (int) $admin->id, $now),
    'grandfatheredUserId' => $grandId,
    'grandfatheredUsername' => 'tech_a1b2c3d4',
    'grandfatheredNickname' => 'tech_harness20031',
    'grandfatheredMemberNickname' => 'tech_#'.$grandId,
    'grandfatheredToken' => rememberToken($db, $schema, $grandId, $now),
    'newUserId' => $newId,
    'newUsername' => 'tech_b2c3d4e5',
    'newNickname' => 'tech_#'.$newId,
    'newToken' => rememberToken($db, $schema, $newId, $now),
    'discussionId' => $discussionId,
    'discussionSlug' => 'harness-discussion',
    'cookieName' => 'flarum_remember',
];

file_put_contents($outFile, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, "FLARUM_SPA_SEED=PASS\n");
