<?php

declare(strict_types=1);

$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
$authFile = getenv('F2_AUTH_FILE') ?: '';
if (! str_starts_with($root, '/tmp/') || ! str_starts_with($authFile, '/tmp/')) throw new RuntimeException('Disposable fixture required');
chdir($root);
require_once $root.'/vendor/autoload.php';
$site = require $root.'/site.php'; $app = $site->bootApp();
$fixture = json_decode(file_get_contents($authFile), true, 512, JSON_THROW_ON_ERROR);
$client = $app->getContainer()->make(\Flarum\Api\Client::class);
$db = $app->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
if (! str_starts_with($db->getDatabaseName(), 'fr_f2_acceptance_')) throw new RuntimeException('Unexpected database');
$member = \Flarum\User\User::findOrFail($fixture['users']['member']['id']);
$peer = \Flarum\User\User::findOrFail($fixture['users']['peer']['id']);
$guest = new \Flarum\User\Guest();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) throw new RuntimeException('FAIL '.$message);
    $checks++; echo 'PASS '.$message."\n";
};
$request = static function ($actor, string $method, string $path, ?array $data = null, array $query = []) use ($client): array {
    parse_str((string) parse_url($path, PHP_URL_QUERY), $embeddedQuery);
    $path = (string) parse_url($path, PHP_URL_PATH);
    $api = $client->withActor($actor)->withQueryParams(array_merge($embeddedQuery, $query));
    if ($data !== null) $api = $api->withBody($data);
    $response = $api->{strtolower($method)}($path);
    $raw = (string) $response->getBody();
    return [$response->getStatusCode(), json_decode($raw, true) ?? [], $raw];
};
$resource = static fn (string $type, int|string $id, array $attributes): array => ['data' => ['type' => $type, 'id' => (string) $id, 'attributes' => $attributes]];

[$status, $data] = $request($member, 'PATCH', '/tags/'.$fixture['brandTag'], $resource('tags', $fixture['brandTag'], ['subscription' => 'follow']));
$check($status === 200, 'follow tag through real provider');
$check($db->table('tag_user')->where('tag_id', $fixture['brandTag'])->where('user_id', $member->id)->value('subscription') === 'follow', 'tag follow persisted');
[$status] = $request($guest, 'PATCH', '/tags/'.$fixture['brandTag'], $resource('tags', $fixture['brandTag'], ['subscription' => 'follow']));
$check(in_array($status, [401, 403], true), 'guest cannot follow tag');
[$status] = $request($member, 'PATCH', '/discussions/'.$fixture['discussion'], $resource('discussions', $fixture['discussion'], ['subscription' => 'follow']));
$check($status === 200, 'follow discussion through native subscriptions');

[$status, $data] = $request($peer, 'POST', '/posts', ['data' => ['type' => 'posts', 'attributes' => ['content' => '**Functional reply** with `code`.', 'flatRateJobBreakdown' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $fixture['discussion']]]]]]);
$check($status === 201, 'post reply with real formatter and notifications: status '.$status);
$reply = (int) ($data['data']['id'] ?? 0);
$check($db->table('flatrate_post_markers')->where('post_id', $reply)->where('marker_key', \FlatRate\SupabaseOAuth\Markers\PostMarkerStore::JOB_BREAKDOWN)->exists(), 'reply marker persisted');
[$status, $data] = $request($peer, 'GET', '/posts/'.$reply);
$check($status === 200 && str_contains($data['data']['attributes']['contentHtml'] ?? '', '<strong>Functional reply</strong>'), 'Markdown rendered by real formatter');
[$status] = $request($member, 'PATCH', '/posts/'.$reply, $resource('posts', $reply, ['content' => 'Unauthorized edit']));
if ($status === 429) {
    // A fresh fixture author just created the discussion. Let the native
    // actor flood window expire before testing the deeper ownership boundary.
    sleep(11);
    [$status] = $request($member, 'PATCH', '/posts/'.$reply, $resource('posts', $reply, ['content' => 'Unauthorized edit']));
}
$check(in_array($status, [401, 403], true), 'another member cannot edit reply: status '.$status);
$check(!str_contains(\Flarum\Post\Post::findOrFail($reply)->content, 'Unauthorized edit'), 'denied edit preserves reply content');
[$status, $data] = $request($peer, 'PATCH', '/posts/'.$reply, $resource('posts', $reply, ['content' => '**Edited functional reply**', 'flatRateJobBreakdown' => true]));
if ($status === 429) {
    $check(true, 'immediate content edits respect native flood protection');
    sleep(11);
    [$status, $data] = $request($peer, 'PATCH', '/posts/'.$reply, $resource('posts', $reply, ['content' => '**Edited functional reply**', 'flatRateJobBreakdown' => true]));
}
$check($status === 200, 'author edits reply: status '.$status.' '.json_encode($data['errors'] ?? []));
$check(str_contains(\Flarum\Post\Post::findOrFail($reply)->content, 'Edited functional reply'), 'edited reply persisted');
foreach ([[], ['include' => 'subject']] as $query) {
    [$status] = $request($member, 'GET', '/notifications', null, $query);
    $check($status === 200, 'notifications resource and subject include');
}

[$status] = $request($member, 'POST', '/masquerade-answers/configure/'.$member->id, [(string) $fixture['brandField'] => 'Ford']);
$check($status === 200, 'member edits own Masquerade profile');
$check($db->table('fof_masquerade_answers')->where('field_id', $fixture['brandField'])->where('user_id', $member->id)->value('content') === 'Ford', 'Masquerade answer persisted');
[$status] = $request($peer, 'POST', '/masquerade-answers/configure/'.$member->id, [(string) $fixture['brandField'] => 'Toyota']);
$check(in_array($status, [401, 403], true), 'another member cannot edit profile');
[$status] = $request($guest, 'POST', '/masquerade-answers/configure/'.$member->id, [(string) $fixture['brandField'] => 'Toyota']);
$check(in_array($status, [401, 403], true), 'guest cannot edit profile');
[$status] = $request($member, 'POST', '/masquerade-answers/configure/'.$member->id, [(string) $fixture['brandField'] => 'INVALID BRAND']);
$check($status === 422, 'profile field validation rejects invalid brand');
$request($member, 'POST', '/masquerade-answers/configure/'.$member->id, [(string) $fixture['brandField'] => 'Chevrolet']);
foreach (['/posts?include=user,user.masqueradeAnswers,user.masqueradeAnswers.field', '/users/'.$member->id.'?include=masqueradeAnswers,masqueradeAnswers.field', '/discussions/'.$fixture['discussion']] as $path) {
    [$status, , $raw] = $request($guest, 'GET', $path);
    $check($status === 200 && !str_contains($raw, 'masquerade-answers') && !str_contains($raw, 'QUAL_RESTRICTED_PROFILE_VALUE'), 'guest profile/explicit-include privacy '.$path);
}
[$status, , $raw] = $request($peer, 'GET', '/users/'.$member->id, null, ['include' => 'masqueradeAnswers,masqueradeAnswers.field']);
$check($status === 200 && !str_contains($raw, 'QUAL_RESTRICTED_PROFILE_VALUE'), 'restricted field hidden from another member');
[$status] = $request($guest, 'GET', '/discussions/'.$fixture['privateDiscussion']);
$check(in_array($status, [401,403,404], true), 'private discussion denied to guest');
[$status] = $request($peer, 'GET', '/discussions/'.$fixture['privateDiscussion']);
$check(in_array($status, [401,403,404], true), 'private discussion denied to unrelated member');

[$status, $data] = $request($peer, 'POST', '/polls', ['data' => ['type' => 'polls', 'attributes' => ['question' => 'Which diagnostic step should come first?', 'options' => [['answer' => 'Inspect wiring'], ['answer' => 'Check fault codes']], 'publicPoll' => false, 'allowChangeVote' => true, 'allowMultipleVotes' => false, 'maxVotes' => 1, 'hideVotes' => false], 'relationships' => ['post' => ['data' => ['type' => 'posts', 'id' => (string) $reply]]]]]);
$check($status === 201, 'create poll through actual provider: status '.$status);
$poll = (int) $data['data']['id'];
$option = (int) \FoF\Polls\PollOption::where('poll_id', $poll)->firstOrFail()->id;
[$status] = $request($peer, 'PATCH', '/polls/'.$poll.'/votes', ['data' => ['optionIds' => [(string) $option]]]);
$check($status === 200, 'member votes in poll');
$check(\FoF\Polls\PollVote::where('poll_id', $poll)->where('user_id', $peer->id)->where('option_id', $option)->exists(), 'poll vote persisted');
[$status] = $request($guest, 'PATCH', '/polls/'.$poll.'/votes', ['data' => ['optionIds' => [(string) $option]]]);
$check(in_array($status, [401,403], true), 'guest cannot vote');

[$status] = $request($peer, 'PATCH', '/posts/'.$fixture['firstPost'], $resource('posts', $fixture['firstPost'], ['reaction' => (string) $fixture['reaction']]));
$check($status === 200, 'member reacts to another author');
$check(\FoF\Reactions\PostReaction::where('post_id', $fixture['firstPost'])->where('user_id', $peer->id)->exists(), 'reaction persisted');
[$status] = $request($guest, 'PATCH', '/posts/'.$fixture['firstPost'], $resource('posts', $fixture['firstPost'], ['reaction' => (string) $fixture['reaction']]));
$check(in_array($status, [401,403], true), 'guest reaction denied');
$fixture['reply'] = $reply; $fixture['poll'] = $poll;
file_put_contents($authFile, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "F2_MEMBER_FEATURES=PASS CHECKS={$checks}\n";
