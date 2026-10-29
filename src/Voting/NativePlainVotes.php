<?php

declare(strict_types=1);

namespace FlatRate\SupabaseOAuth\Voting;

use FlatRate\SupabaseOAuth\Activity\EmitVoteActivity;
use Flarum\Foundation\ValidationException;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Flarum 2 plain ballots, with no dependency on the retired Gamification UI. */
final class NativePlainVotes
{
    public function __construct(
        private ConnectionInterface $db,
        private VoteSafetyGate $gate,
        private EmitVoteActivity $activity
    ) {}

    public function canVote(User $actor, Post $post): bool
    {
        try {
            return $post->exists && $post->type === 'comment' && $post->hidden_at === null
                && !$actor->isGuest()
                && (!$actor->suspended_until || $actor->suspended_until->isPast())
                && $this->gate->allowsVoteMutation($actor, $post)
                && $actor->hasPermission('discussion.votePosts')
                && $post->isVisibleTo($actor)
                && \Flarum\Discussion\Discussion::whereVisibleTo($actor)->where('discussions.id', $post->discussion_id)->exists()
                && $this->db->getSchemaBuilder()->hasColumns('post_votes', ['post_id', 'user_id', 'value', 'created_at', 'updated_at']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function count(Post $post): int
    {
        try {
            return $this->gate->isEnabled() ? $this->db->table('post_votes')->where('post_id', $post->id)->where('value', '>', 0)->count() : 0;
        } catch (\Throwable) { return 0; }
    }

    public function mine(User $actor, Post $post): bool
    {
        try {
            return !$actor->isGuest() && $this->gate->isEnabled()
                && $this->db->table('post_votes')->where('post_id', $post->id)->where('user_id', $actor->id)->where('value', '>', 0)->exists();
        } catch (\Throwable) { return false; }
    }

    public function cast(User $actor, Post $post, int $value): void
    {
        if (!in_array($value, [0, 1], true)) throw new ValidationException(['flatRateVote' => 'Only an upvote or its removal is supported.']);
        if (!$this->canVote($actor, $post)) throw new PermissionDeniedException();
        $created = $this->db->transaction(function () use ($actor, $post, $value): bool {
            // Serialize the first insert as well as repeat writes. The unique
            // key is a second defense; retries cannot create extra ballots.
            $this->db->table('posts')->where('id', $post->id)->lockForUpdate()->first();
            $fresh = Post::findOrFail($post->id);
            if (!$this->canVote($actor, $fresh)) throw new PermissionDeniedException();
            $key = ['post_id' => (int) $post->id, 'user_id' => (int) $actor->id];
            $old = $this->db->table('post_votes')->where($key)->first();
            $now = gmdate('Y-m-d H:i:s');
            if ($old) {
                $this->db->table('post_votes')->where($key)->update(['value' => $value, 'updated_at' => $now]);
            } elseif ($value === 1) {
                $this->db->table('post_votes')->insert($key + ['value' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            return $old === null;
        });
        // Reuse the existing, fail-closed activity observer. Emission stays
        // behind its own independent gate; it cannot roll back the ballot.
        $this->activity->handle((object) ['vote' => (object) ['user' => $actor, 'post' => $post, 'value' => $value, 'wasRecentlyCreated' => $created]]);
    }
}
