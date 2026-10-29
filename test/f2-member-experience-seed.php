<?php

declare(strict_types=1);

// Synthetic data only. Never run this fixture builder against a hosted forum.
$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
$authFile = getenv('F2_AUTH_FILE') ?: '';
if (! str_starts_with($root, '/tmp/') || ! str_starts_with($authFile, '/tmp/')) {
    throw new RuntimeException('Disposable FLARUM_ROOT and F2_AUTH_FILE are required');
}
chdir($root);
require_once $root.'/vendor/autoload.php';
$site = require $root.'/site.php';
$app = $site->bootApp();
$db = $app->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
$settings = $app->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
if (! str_starts_with((string) $db->getDatabaseName(), 'fr_f2_acceptance_')) {
    throw new RuntimeException('Unexpected database: refusing fixture writes');
}
$settings->set('forum_title', 'FLATRATE.WIKI');
$settings->set('welcome_title', 'Automotive Technicians Anonymous');
$settings->set('welcome_message', 'Speak up. Work together. Share ideas.');
$settings->set('theme_primary_color', '#c32659');
$settings->set('color_scheme', 'dark');
$settings->set('mail_driver', 'log');
$settings->set('flatrate-voting.enabled', '1');
$settings->set('flatrate-activity.emit_enabled', '0');
$settings->set('fof-sitemap.excludeUsers', '1');
$settings->set('masquerade.force-profile-completion', '0');
$settings->set('fof-polls.allowPollsInReplies', '1');

foreach (['discussion.votePosts', 'startConversation', 'discussion.start', 'discussion.reply', 'discussion.editOwn', 'discussion.react', 'discussion.polls.start', 'discussion.polls.vote', 'discussion.polls.viewResultsWithoutVoting', 'fof.masquerade.have-profile', 'fof.masquerade.view-profile', 'user.editOwnNickname'] as $permission) {
    $db->table('group_permission')->updateOrInsert(['group_id' => 3, 'permission' => $permission], []);
}

$admin = \Flarum\User\User::where('username', 'qualadmin')->firstOrFail();
$users = ['admin' => $admin];
foreach (['member' => ['tech_qual42', 'MasterTech42'], 'peer' => ['tech_qual43', 'Peer Technician'], 'outsider' => ['tech_qual44', 'Outside Technician']] as $role => [$username, $nickname]) {
    $user = \Flarum\User\User::where('username', $username)->first() ?? new \Flarum\User\User();
    $user->username = $username;
    $user->email = $username.'@example.test';
    $user->nickname = $nickname;
    $user->is_email_confirmed = 1;
    $user->joined_at ??= new DateTimeImmutable();
    if (! $user->exists) $user->password = bin2hex(random_bytes(24));
    $user->save();
    $user->groups()->syncWithoutDetaching([3]);
    $app->getContainer()->make(\FlatRate\SupabaseOAuth\Identity\MemberProfileStore::class)->selfHeal($user);
    $users[$role] = $user;
}

$manifest = \FlatRate\ForumNavigation\NavigationManifest::load();
$position = 1;
$brandNames = [];
$walk = static function (array $nodes, ?int $parent = null) use (&$walk, &$position, &$brandNames): void {
    foreach ($nodes as $node) {
        $brandNames[] = $node['name'];
        $tag = \Flarum\Tags\Tag::where('slug', $node['slug'])->first() ?? new \Flarum\Tags\Tag();
        $tag->slug = $node['slug'];
        $tag->name = $node['name'];
        $tag->description = 'Repair discussions for '.$node['name'];
        $tag->color = '#c32659';
        $tag->position = $parent ? null : $position++;
        $tag->parent_id = $parent;
        $tag->save();
        $walk($node['children'] ?? [], (int) $tag->id);
    }
};
$walk($manifest['groups'][2]['boards']);
foreach (['start-here' => 'Start Here', 'general-shop-discussion' => 'General Shop Discussion', 'tip' => 'Tip'] as $slug => $name) {
    $tag = \Flarum\Tags\Tag::where('slug', $slug)->first() ?? new \Flarum\Tags\Tag();
    $tag->slug = $slug; $tag->name = $name; $tag->position = $position++; $tag->color = '#c32659'; $tag->save();
}
$brand = \Flarum\Tags\Tag::where('slug', 'chevrolet')->firstOrFail();
$private = \Flarum\Tags\Tag::where('slug', 'qualification-private')->first() ?? new \Flarum\Tags\Tag();
$private->slug = 'qualification-private'; $private->name = 'Private Qualification'; $private->position = $position++; $private->is_hidden = true; $private->is_restricted = true; $private->save();

$field = \FoF\Masquerade\Field::where('name', 'Affiliated Brand')->first() ?? new \FoF\Masquerade\Field();
$field->name = 'Affiliated Brand'; $field->type = 'select'; $field->sort = 1; $field->on_bio = false; $field->is_restricted = false; $field->required = false;
$field->validation = 'in:'.implode(',', array_unique($brandNames));
$field->save();
$db->table('fof_masquerade_answers')->updateOrInsert(['field_id' => $field->id, 'user_id' => $users['member']->id], ['content' => 'Chevrolet', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
$secret = \FoF\Masquerade\Field::where('name', 'Qualification Private Detail')->first() ?? new \FoF\Masquerade\Field();
$secret->name = 'Qualification Private Detail'; $secret->sort = 2; $secret->on_bio = false; $secret->is_restricted = true; $secret->required = false; $secret->save();
$db->table('fof_masquerade_answers')->updateOrInsert(['field_id' => $secret->id, 'user_id' => $users['member']->id], ['content' => 'QUAL_RESTRICTED_PROFILE_VALUE', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

$client = $app->getContainer()->make(\Flarum\Api\Client::class);
$createDiscussion = static function ($actor, string $title, string $content, $tag) use ($client): \Flarum\Discussion\Discussion {
    $existing = \Flarum\Discussion\Discussion::where('title', $title)->first();
    if ($existing) return $existing;
    $tags = [['type' => 'tags', 'id' => (string) $tag->id]];
    if ($tag->parent_id) $tags[] = ['type' => 'tags', 'id' => (string) $tag->parent_id];
    $response = $client->withActor($actor)->withBody(['data' => ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => $content], 'relationships' => ['tags' => ['data' => $tags]]]])->post('/discussions');
    $payload = json_decode((string) $response->getBody(), true);
    if ($response->getStatusCode() !== 201) throw new RuntimeException('Fixture discussion failed: '.json_encode($payload['errors'] ?? $response->getStatusCode()));
    return \Flarum\Discussion\Discussion::findOrFail($payload['data']['id']);
};
$discussion = $createDiscussion($users['member'], 'Qualification Chevrolet repair discussion', '**Verified formatting** for a safe repair. What should we inspect next?', $brand);
$privateDiscussion = $createDiscussion($admin, 'QUAL_PRIVATE_DISCUSSION_TITLE', 'QUAL_PRIVATE_POST_SHOULD_NEVER_APPEAR', $private);
$reaction = \FoF\Reactions\Reaction::first();
if (! $reaction) {
    $reaction = new \FoF\Reactions\Reaction(); $reaction->identifier = 'thumbsup'; $reaction->display = 'Helpful'; $reaction->type = 'emoji'; $reaction->enabled = true; $reaction->save();
}

$config = require $root.'/config.php';
$auth = ['baseUrl' => $config['url'], 'discussion' => (int) $discussion->id, 'firstPost' => (int) $discussion->first_post_id, 'privateDiscussion' => (int) $privateDiscussion->id, 'brandTag' => (int) $brand->id, 'brandField' => (int) $field->id, 'privateField' => (int) $secret->id, 'reaction' => (int) $reaction->id, 'users' => []];
$auth['ssoSecret'] = bin2hex(random_bytes(32));
$settings->set('fof-oauth.flatrate.sso_shared_secret', $auth['ssoSecret']);
foreach ($users as $role => $user) {
    $token = \Flarum\Http\RememberAccessToken::generate($user->id); $token->save();
    $auth['users'][$role] = ['id' => (int) $user->id, 'username' => $user->username, 'token' => $token->token];
}
file_put_contents($authFile, json_encode($auth, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($authFile, 0600);
echo 'F2_SYNTHETIC_FIXTURE=PASS USERS='.count($users).' DISCUSSION='.$discussion->id.' TAGS='.\Flarum\Tags\Tag::count()."\n";
