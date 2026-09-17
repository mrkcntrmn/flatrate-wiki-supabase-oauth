<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Fail-closed FlatRate vote mutation gate.
 *
 * FLATRATE_POLICY_FORCE_ALLOW=false — this gate only denies or abstains.
 */
final class VoteSafetyGate
{
    public const SETTING_ENABLED = 'flatrate-voting.enabled';

    public function __construct(
        private Container $container,
        private SettingsRepositoryInterface $settings,
        private VotingReadiness $readiness
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_ENABLED);
    }

    /**
     * Whether FlatRate allows the mutation to proceed to FoF provider policy.
     */
    public function allowsVoteMutation(?User $actor, ?Post $post): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($this->isPusherBound()) {
            return false;
        }

        $report = $this->readiness->inspectActivitySchema();
        if ($report['vote_state_table_present'] !== true
            || $report['required_columns_present'] !== true
            || $report['outbox_table_present'] !== true
            || $report['outbox_terminal_at_present'] !== true) {
            return false;
        }

        if ($actor === null || $post === null) {
            return false;
        }

        if ($actor->isGuest()) {
            return false;
        }

        if ($this->isSelfVote($actor, $post)) {
            return false;
        }

        return true;
    }

    public function isPusherBound(): bool
    {
        // FoF Gamification 1.6.12: use Pusher; container->bound(Pusher::class)
        // where Pusher::class === 'Pusher'.
        return $this->container->bound('Pusher');
    }

    public function isSelfVote(User $actor, Post $post): bool
    {
        return (int) $actor->id === (int) $post->user_id;
    }
}
