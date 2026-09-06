<?php

namespace FlatRate\SupabaseOAuth\Subscription;

/**
 * Pure eligibility rules mirroring FoF Follow Tags 1.3.0 semantics with
 * FlatRate family effective-subscription resolution.
 *
 * Caught-up threshold (exact 1.3.0):
 *   last_read_post_number >= lastPostNumber - 1
 * where jobs pass lastPostNumber = post.number - 1, i.e.
 *   last_read >= post.number - 2
 */
final class FollowTagsFamilyRecipientEvaluator
{
    public const MODE_NEW_DISCUSSION = 'new_discussion';
    public const MODE_NEW_POST = 'new_post';
    public const MODE_NEW_DISCUSSION_TAG = 'new_discussion_tag';

    public function __construct(
        private TagFamilyRegistry $registry,
        private EffectiveTagSubscriptionResolver $resolver
    ) {
    }

    /**
     * @param list<array{id:int,slug:string}> $discussionTags
     * @param array<int, array<int, ?string>> $subscriptions [userId][tagId] => subscription|null
     * @param array<int, array{id:int,slug:string}> $tagsById
     */
    public function involvesFamily(array $discussionTags): bool
    {
        foreach ($discussionTags as $tag) {
            if ($this->registry->isFamilyMember($tag['slug'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{id:int,slug:string}> $discussionTags
     * @param array<int, ?string> $userSubscriptionsByTagId
     * @param array<string, int> $slugToId
     */
    public function effectiveForTags(
        array $discussionTags,
        array $userSubscriptionsByTagId,
        array $slugToId
    ): array {
        $effective = [];
        foreach ($discussionTags as $tag) {
            $slug = $tag['slug'];
            $tagId = $tag['id'];
            $direct = $userSubscriptionsByTagId[$tagId] ?? null;
            $rootSub = null;
            $root = $this->registry->familyRootFor($slug);
            if ($root !== null && $this->registry->isFamilyChild($slug)) {
                $rootId = $slugToId[$root] ?? null;
                if ($rootId !== null) {
                    $rootSub = $userSubscriptionsByTagId[$rootId] ?? null;
                }
            }
            $effective[$tagId] = $this->resolver->resolve($slug, $direct, $rootSub);
        }

        return $effective;
    }

    /**
     * @param list<array{id:int,slug:string}> $discussionTags
     * @param array<int, ?string> $userSubscriptionsByTagId
     * @param array<string, int> $slugToId
     */
    public function isEligible(
        string $mode,
        int $userId,
        ?int $excludeUserId,
        array $discussionTags,
        array $userSubscriptionsByTagId,
        array $slugToId,
        bool $discussionVisible,
        bool $postVisible,
        bool $isReader = true,
        ?int $lastReadPostNumber = null,
        ?int $lastPostNumber = null
    ): bool {
        if ($excludeUserId !== null && $userId === $excludeUserId) {
            return false;
        }

        if (!$discussionVisible || !$postVisible) {
            return false;
        }

        if ($mode === self::MODE_NEW_POST) {
            if (!$isReader) {
                return false;
            }
            if ($lastReadPostNumber === null || $lastPostNumber === null) {
                return false;
            }
            // Exact FoF 1.3.0: last_read >= lastPostNumber - 1
            if ($lastReadPostNumber < ($lastPostNumber - 1)) {
                return false;
            }
        }

        $effective = $this->effectiveForTags($discussionTags, $userSubscriptionsByTagId, $slugToId);

        foreach ($effective as $state) {
            if ($state === 'ignore') {
                return false;
            }
        }

        if ($mode === self::MODE_NEW_POST) {
            foreach ($effective as $state) {
                if ($state === 'lurk') {
                    return true;
                }
            }

            return false;
        }

        // new discussion / re-tag
        foreach ($effective as $state) {
            if ($state === 'follow' || $state === 'lurk') {
                return true;
            }
        }

        return false;
    }

    /**
     * Inherited ignore for mention notifications.
     *
     * @param list<array{id:int,slug:string}> $discussionTags
     * @param array<int, ?string> $userSubscriptionsByTagId
     * @param array<string, int> $slugToId
     */
    public function shouldSuppressMention(
        array $discussionTags,
        array $userSubscriptionsByTagId,
        array $slugToId
    ): bool {
        if (!$this->involvesFamily($discussionTags)) {
            // Non-family: only direct ignore on discussion tags (FoF already handles;
            // this filter is additive for inheritance only).
            foreach ($discussionTags as $tag) {
                $direct = $userSubscriptionsByTagId[$tag['id']] ?? null;
                if ($this->resolver->normalize($direct) === 'ignore') {
                    return true;
                }
            }

            return false;
        }

        $effective = $this->effectiveForTags($discussionTags, $userSubscriptionsByTagId, $slugToId);
        foreach ($effective as $state) {
            if ($state === 'ignore') {
                return true;
            }
        }

        return false;
    }
}
