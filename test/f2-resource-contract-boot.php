<?php

declare(strict_types=1);

/** Real Flarum resource regression checks against a disposable synthetic fixture. */
$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
if (! str_starts_with($root, '/tmp/') || ! is_file($root.'/site.php')) {
    throw new RuntimeException('FLARUM_ROOT must point to a disposable /tmp Flarum installation');
}
chdir($root);
require_once $root.'/vendor/autoload.php';
$site = require $root.'/site.php';
$app = $site->bootApp();
$client = $app->getContainer()->make(\Flarum\Api\Client::class);
$member = \Flarum\User\User::where('username', getenv('CONTRACT_MEMBER') ?: 'tech_#42')->firstOrFail();
$peer = \Flarum\User\User::where('id', '!=', $member->id)->firstOrFail();
$guest = new \Flarum\User\Guest();
$discussion = getenv('CONTRACT_DISCUSSION') ?: '3';
$failures = [];
$checks = 0;
$check = static function (bool $ok, string $name) use (&$failures, &$checks): void {
    $checks++;
    if (! $ok) {
        $failures[] = $name;
    }
};
$request = static function ($actor, string $path) use ($client, $check): array {
    $response = $client->withActor($actor)->get($path);
    $check($response->getStatusCode() === 200, $path.' HTTP 200');
    return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data']['attributes'] ?? [];
};

$self = $request($member, '/users/'.$member->id);
foreach (['flatRateMemberNumber', 'flatRateMemberNickname', 'flatRateNicknameMode', 'flatRateCustomNickname', 'flatRateCustomNicknameOrigin', 'flatRateOwnerDashboard'] as $key) {
    $check(array_key_exists($key, $self), 'owner resource includes '.$key);
}
$profile = \FlatRate\SupabaseOAuth\Identity\MemberProfile::query()->find($member->id);
$check(($self['flatRateNicknameMode'] ?? null) === $profile?->display_mode, 'owner nickname mode matches persisted profile');
$check(($self['flatRateCustomNickname'] ?? null) === $profile?->custom_nickname, 'owner retained nickname matches persisted profile');
$check(($self['flatRateMemberNickname'] ?? null) === \FlatRate\SupabaseOAuth\Identity\MemberIdentity::nickname((int) $member->id), 'member nickname follows the established identity contract');
foreach ([$peer, $guest] as $actor) {
    $attrs = $request($actor, '/users/'.$member->id);
    foreach (['flatRateNicknameMode', 'flatRateCustomNickname', 'flatRateCustomNicknameOrigin', 'flatRateOwnerDashboard'] as $key) {
        $check(! array_key_exists($key, $attrs), 'non-owner omits '.$key);
    }
    if ($actor->isGuest()) {
        $check(! array_key_exists('flatRateMemberNumber', $attrs), 'guest omits member number');
        $check(! array_key_exists('flatRateMemberNickname', $attrs), 'guest omits member nickname');
    } else {
        $check(array_key_exists('flatRateMemberNickname', $attrs), 'authenticated peer retains public member nickname');
    }
}

foreach ([$member, $guest] as $actor) {
    $forum = $request($actor, '/');
    $check(array_key_exists('flatRateVotingEnabled', $forum) && is_bool($forum['flatRateVotingEnabled']), 'forum exposes boolean voting gate');
    $attrs = $request($actor, '/discussions/'.$discussion);
    foreach (['flatRateDiscussionUpvotes', 'flatRateDiscussionViewerUpvoted', 'flatRateDiscussionViewerVotePostId', 'flatRateDiscussionCanUpvote'] as $key) {
        $check(array_key_exists($key, $attrs), 'discussion includes '.$key);
    }
    $check(is_int($attrs['flatRateDiscussionUpvotes'] ?? null), 'discussion count is integer');
    if ($actor->isGuest()) {
        $check(($attrs['flatRateDiscussionCanUpvote'] ?? null) === false, 'guest cannot vote');
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL '.$failure."\n");
}
echo 'F2_RESOURCE_CONTRACT='.($failures === [] ? 'PASS' : 'FAIL')." CHECKS={$checks} FAILURES=".count($failures)."\n";
exit($failures === [] ? 0 : 1);
