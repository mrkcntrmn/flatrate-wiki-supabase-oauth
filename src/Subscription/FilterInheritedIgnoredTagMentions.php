<?php

namespace FlatRate\SupabaseOAuth\Subscription;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * beforeSending filter that removes mention recipients when effective
 * (including inherited family-root) subscription is ignore.
 *
 * Only removes recipients — never adds family Follow Tags recipients.
 * Scope: PostMentionedBlueprint / UserMentionedBlueprint only.
 */
final class FilterInheritedIgnoredTagMentions
{
    private const POST_MENTIONED = 'Flarum\\Mentions\\Notification\\PostMentionedBlueprint';
    private const USER_MENTIONED = 'Flarum\\Mentions\\Notification\\UserMentionedBlueprint';

    public function __construct(
        private TagFamilyRegistry $registry,
        private EffectiveTagSubscriptionResolver $subscriptionResolver,
        private FollowTagsFamilyRecipientEvaluator $evaluator,
        private ConnectionInterface $db
    ) {
    }

    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        $class = get_class($blueprint);
        if ($class !== self::POST_MENTIONED && $class !== self::USER_MENTIONED) {
            return $recipients;
        }

        $post = $blueprint->post ?? null;
        if (!$post || !isset($post->discussion)) {
            return $recipients;
        }

        $discussion = $post->discussion;
        $discussionTags = [];
        $slugToId = [];
        foreach ($discussion->tags ?? [] as $tag) {
            if (!isset($tag->id, $tag->slug)) {
                continue;
            }
            $id = (int) $tag->id;
            $slug = (string) $tag->slug;
            $discussionTags[] = ['id' => $id, 'slug' => $slug];
            $slugToId[$slug] = $id;
        }

        if ($discussionTags === []) {
            return $recipients;
        }

        // Only apply FlatRate inheritance path when a family tag is present.
        // Direct ignore on non-family tags remains FoF's PreventMentionNotificationsFromIgnoredTags.
        if (!$this->evaluator->involvesFamily($discussionTags)) {
            return $recipients;
        }

        foreach ($discussionTags as $tag) {
            $root = $this->registry->familyRootFor($tag['slug']);
            if ($root === null || isset($slugToId[$root])) {
                continue;
            }
            $rootId = $this->db->table('tags')->where('slug', $root)->value('id');
            if ($rootId !== null) {
                $slugToId[$root] = (int) $rootId;
            }
        }

        $userIds = [];
        foreach ($recipients as $user) {
            if (isset($user->id)) {
                $userIds[] = (int) $user->id;
            }
        }
        if ($userIds === []) {
            return $recipients;
        }

        $tagIds = array_values(array_unique(array_values($slugToId)));
        $subs = [];
        $rows = $this->db->table('tag_user')
            ->whereIn('user_id', $userIds)
            ->whereIn('tag_id', $tagIds)
            ->get(['user_id', 'tag_id', 'subscription']);
        foreach ($rows as $row) {
            $subs[(int) $row->user_id][(int) $row->tag_id] = $this->subscriptionResolver->normalize(
                isset($row->subscription) ? (string) $row->subscription : null
            );
        }

        return array_values(array_filter($recipients, function ($user) use ($discussionTags, $subs, $slugToId) {
            if (!isset($user->id)) {
                return true;
            }
            $uid = (int) $user->id;

            return !$this->evaluator->shouldSuppressMention(
                $discussionTags,
                $subs[$uid] ?? [],
                $slugToId
            );
        }));
    }
}
