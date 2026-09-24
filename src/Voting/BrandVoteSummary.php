<?php

namespace FlatRate\SupabaseOAuth\Voting;

use FlatRate\SupabaseOAuth\Activity\BrandContext;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Actor-visible exact-Brand positive-vote aggregates.
 *
 * Reuses FlatRate's canonical primary-Brand resolver and whole-discussion
 * positive-vote semantics. No voter identities are serialized.
 */
final class BrandVoteSummary
{
    public function __construct(
        private ConnectionInterface $db,
        private VoteSafetyGate $gate,
        private BrandContext $brands
    ) {
    }

    /**
     * @return array<string,int>|null Slug => positive vote count; null means unavailable.
     */
    public function forActor(?User $actor): ?array
    {
        if (! $actor || ! $this->gate->isEnabled() || ! $this->tablesPresent()) {
            return null;
        }

        try {
            $totals = array_fill_keys($this->brands->acceptedBrandSlugs(), 0);

            $visibleDiscussionIds = Discussion::query()
                ->whereVisibleTo($actor)
                ->pluck('discussions.id')
                ->map(static fn ($id) => (int) $id)
                ->filter(static fn ($id) => $id > 0)
                ->values()
                ->all();

            if ($visibleDiscussionIds === []) {
                return $totals;
            }

            $tagRows = $this->db->table('discussion_tag')
                ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
                ->whereIn('discussion_tag.discussion_id', $visibleDiscussionIds)
                ->whereNotNull('tags.position')
                ->whereIn('tags.slug', $this->brands->acceptedBrandSlugs())
                ->orderBy('discussion_tag.discussion_id')
                ->orderBy('tags.position')
                ->orderBy('tags.id')
                ->get([
                    'discussion_tag.discussion_id',
                    'tags.slug',
                    'tags.position',
                ]);

            $tagsByDiscussion = [];
            foreach ($tagRows as $row) {
                $discussionId = (int) $row->discussion_id;
                $tagsByDiscussion[$discussionId][] = [
                    'slug' => (string) $row->slug,
                    'position' => $row->position,
                ];
            }

            $brandByDiscussion = [];
            foreach ($tagsByDiscussion as $discussionId => $tags) {
                $slug = $this->brands->brandSlugFromPrimaryTags($tags);
                if ($slug !== null) {
                    $brandByDiscussion[(int) $discussionId] = $slug;
                }
            }

            if ($brandByDiscussion === []) {
                return $totals;
            }

            $voteRows = $this->db->table('post_votes')
                ->join('posts', 'posts.id', '=', 'post_votes.post_id')
                ->whereIn('posts.discussion_id', array_keys($brandByDiscussion))
                ->where('post_votes.value', '>', 0)
                ->where('posts.type', 'comment')
                ->whereNull('posts.hidden_at')
                ->groupBy('posts.discussion_id')
                ->get([
                    'posts.discussion_id',
                    $this->db->raw('COUNT(*) AS positive_vote_count'),
                ]);

            foreach ($voteRows as $row) {
                $discussionId = (int) $row->discussion_id;
                $slug = $brandByDiscussion[$discussionId] ?? null;
                if ($slug === null || ! array_key_exists($slug, $totals)) {
                    continue;
                }

                $totals[$slug] += (int) $row->positive_vote_count;
            }

            return $totals;
        } catch (\Throwable) {
            // Fail closed: consumers omit the aggregate surface when unavailable.
            return null;
        }
    }

    private function tablesPresent(): bool
    {
        try {
            $schema = $this->db->getSchemaBuilder();

            foreach (['post_votes', 'posts', 'discussion_tag', 'tags'] as $table) {
                if (! $schema->hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
