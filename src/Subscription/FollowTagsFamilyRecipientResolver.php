<?php

namespace FlatRate\SupabaseOAuth\Subscription;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Resolves final FoF Follow Tags recipients with GM/CDJR family inheritance
 * before NotificationSyncer reconciliation.
 *
 * FoF jobs/blueprints remain unchanged. This only expands/filters the user
 * array that enters parent::sync().
 *
 * Hard dependency on FoF blueprint class names is string-based so the companion
 * boots when fof-follow-tags is absent (Conditional registration).
 */
final class FollowTagsFamilyRecipientResolver
{
    private const NEW_DISCUSSION = 'FoF\\FollowTags\\Notifications\\NewDiscussionBlueprint';
    private const NEW_POST = 'FoF\\FollowTags\\Notifications\\NewPostBlueprint';
    private const NEW_DISCUSSION_TAG = 'FoF\\FollowTags\\Notifications\\NewDiscussionTagBlueprint';

    public function __construct(
        private TagFamilyRegistry $registry,
        private EffectiveTagSubscriptionResolver $subscriptionResolver,
        private FollowTagsFamilyRecipientEvaluator $evaluator,
        private ConnectionInterface $db,
        private FamilyUserLookup $users
    ) {
    }

    /**
     * @param User[] $users
     * @return User[]
     */
    public function resolve(BlueprintInterface $blueprint, array $users): array
    {
        $mode = $this->modeFor($blueprint);
        if ($mode === null) {
            return $users;
        }

        $context = $this->blueprintContext($blueprint, $mode);
        if ($context === null) {
            return $users;
        }

        /** @var list<array{id:int,slug:string}> $discussionTags */
        $discussionTags = $context['tags'];
        if (!$this->evaluator->involvesFamily($discussionTags)) {
            return $users;
        }

        $excludeUserId = $context['exclude_user_id'];
        $slugToId = [];
        $tagIds = [];
        foreach ($discussionTags as $tag) {
            $slugToId[$tag['slug']] = $tag['id'];
            $tagIds[] = $tag['id'];
        }

        $rootIds = [];
        foreach ($discussionTags as $tag) {
            $root = $this->registry->familyRootFor($tag['slug']);
            if ($root === null) {
                continue;
            }
            $rootId = $this->tagIdForSlug($root);
            if ($rootId !== null) {
                $slugToId[$root] = $rootId;
                $rootIds[] = $rootId;
                $tagIds[] = $rootId;
            }
        }
        $tagIds = array_values(array_unique($tagIds));
        $rootIds = array_values(array_unique($rootIds));

        $candidates = $this->indexUsers($users);
        $positive = $mode === FollowTagsFamilyRecipientEvaluator::MODE_NEW_POST
            ? ['lurk']
            : ['follow', 'lurk'];

        foreach ($this->loadRootPositiveUsers($rootIds, $positive) as $user) {
            $candidates[(int) $user->id] = $user;
        }

        if ($candidates === []) {
            return [];
        }

        $subscriptions = $this->bulkLoadSubscriptions(array_keys($candidates), $tagIds);

        $resolved = [];
        foreach ($candidates as $userId => $user) {
            $userSubs = $subscriptions[$userId] ?? [];

            // Fail closed: inherited candidates have not already passed FoF
            // visibility queries. Exceptions and missing checks are ineligible.
            $discussionVisible = false;
            $postVisible = false;
            $isReader = true;
            $lastRead = null;

            if (isset($context['discussion']) && is_object($context['discussion'])) {
                $discussionVisible = $this->isDiscussionVisibleTo($context['discussion'], $user);
            }

            if (isset($context['post']) && is_object($context['post'])) {
                $postVisible = $this->isPostVisibleTo($context['post'], $user);
            } else {
                // New discussion / re-tag / reply all require a visible post in
                // FoF 1.3.0. Missing post model → fail closed.
                $postVisible = false;
            }

            if ($mode === FollowTagsFamilyRecipientEvaluator::MODE_NEW_POST) {
                $isReader = false;
                $lastRead = null;
                if (isset($context['discussion']) && is_object($context['discussion'])) {
                    try {
                        $state = $context['discussion']->stateFor($user);
                        if ($state && $state->last_read_post_number !== null) {
                            $isReader = true;
                            $lastRead = (int) $state->last_read_post_number;
                        }
                    } catch (\Throwable) {
                        $isReader = false;
                    }
                }
            }

            $eligible = $this->evaluator->isEligible(
                $mode,
                (int) $userId,
                $excludeUserId,
                $discussionTags,
                $userSubs,
                $slugToId,
                $discussionVisible,
                $postVisible,
                $isReader,
                $lastRead,
                $context['last_post_number'] ?? null
            );

            if ($eligible) {
                $resolved[(int) $userId] = $user;
            }
        }

        return array_values($resolved);
    }

    /**
     * Discussion visibility for inherited candidates. Any exception fails closed.
     */
    private function isDiscussionVisibleTo(object $discussion, User $user): bool
    {
        try {
            return (bool) $discussion
                ->newQuery()
                ->whereVisibleTo($user)
                ->find($discussion->id);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Post visibility for inherited candidates. Any exception fails closed.
     */
    private function isPostVisibleTo(object $post, User $user): bool
    {
        try {
            return (bool) $post->isVisibleTo($user);
        } catch (\Throwable) {
            return false;
        }
    }

    private function modeFor(BlueprintInterface $blueprint): ?string
    {
        $class = get_class($blueprint);
        return match ($class) {
            self::NEW_DISCUSSION => FollowTagsFamilyRecipientEvaluator::MODE_NEW_DISCUSSION,
            self::NEW_POST => FollowTagsFamilyRecipientEvaluator::MODE_NEW_POST,
            self::NEW_DISCUSSION_TAG => FollowTagsFamilyRecipientEvaluator::MODE_NEW_DISCUSSION_TAG,
            default => null,
        };
    }

    /**
     * @return array{
     *   tags: list<array{id:int,slug:string}>,
     *   exclude_user_id: ?int,
     *   discussion?: object,
     *   post?: object,
     *   last_post_number?: int
     * }|null
     */
    private function blueprintContext(BlueprintInterface $blueprint, string $mode): ?array
    {
        $discussion = null;
        $post = null;
        $exclude = null;
        $lastPostNumber = null;

        if ($mode === FollowTagsFamilyRecipientEvaluator::MODE_NEW_POST) {
            $post = $blueprint->post ?? null;
            if (!$post) {
                return null;
            }
            $discussion = $post->discussion ?? null;
            $exclude = isset($post->user_id) ? (int) $post->user_id : null;
            // FoF job constructs with lastPostNumber = post.number - 1.
            if (isset($post->number)) {
                $lastPostNumber = ((int) $post->number) - 1;
            }
        } elseif ($mode === FollowTagsFamilyRecipientEvaluator::MODE_NEW_DISCUSSION_TAG) {
            $discussion = $blueprint->discussion ?? null;
            $post = $blueprint->post ?? ($discussion->firstPost ?? null);
            $actor = $blueprint->actor ?? null;
            $exclude = $actor && isset($actor->id) ? (int) $actor->id : null;
        } else {
            $discussion = $blueprint->discussion ?? null;
            $post = $blueprint->post ?? ($discussion->firstPost ?? null);
            $exclude = $discussion && isset($discussion->user_id) ? (int) $discussion->user_id : null;
        }

        if (!$discussion) {
            return null;
        }

        $tags = [];
        $rawTags = $discussion->tags ?? [];
        foreach ($rawTags as $tag) {
            if (!isset($tag->id, $tag->slug)) {
                continue;
            }
            $tags[] = ['id' => (int) $tag->id, 'slug' => (string) $tag->slug];
        }

        if ($tags === []) {
            return null;
        }

        return [
            'tags' => $tags,
            'exclude_user_id' => $exclude,
            'discussion' => $discussion,
            'post' => $post,
            'last_post_number' => $lastPostNumber,
        ];
    }

    /**
     * @param User[] $users
     * @return array<int, User>
     */
    private function indexUsers(array $users): array
    {
        $out = [];
        foreach ($users as $user) {
            if ($user instanceof User) {
                $out[(int) $user->id] = $user;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $rootIds
     * @param list<string> $positive
     * @return iterable<User>
     */
    private function loadRootPositiveUsers(array $rootIds, array $positive): iterable
    {
        if ($rootIds === [] || $positive === []) {
            return [];
        }

        $userIds = $this->db->table('tag_user')
            ->whereIn('tag_id', $rootIds)
            ->whereIn('subscription', $positive)
            ->pluck('user_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        return $this->users->findByIds(array_map('intval', $userIds));
    }

    /**
     * @param list<int> $userIds
     * @param list<int> $tagIds
     * @return array<int, array<int, ?string>>
     */
    private function bulkLoadSubscriptions(array $userIds, array $tagIds): array
    {
        $map = [];
        if ($userIds === [] || $tagIds === []) {
            return $map;
        }

        $rows = $this->db->table('tag_user')
            ->whereIn('user_id', $userIds)
            ->whereIn('tag_id', $tagIds)
            ->get(['user_id', 'tag_id', 'subscription']);

        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $tid = (int) $row->tag_id;
            $map[$uid][$tid] = $this->subscriptionResolver->normalize(
                isset($row->subscription) ? (string) $row->subscription : null
            );
        }

        return $map;
    }

    private function tagIdForSlug(string $slug): ?int
    {
        $id = $this->db->table('tags')->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
