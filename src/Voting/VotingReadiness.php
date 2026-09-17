<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Admin-only production readiness facts. No secrets, no row payloads.
 */
final class VotingReadiness
{
    public const SCHEMA_VERSION = 1;

    public const REQUIRED_VOTE_STATE_COLUMNS = [
        'post_id',
        'user_id',
        'last_effective_vote',
        'state_version',
        'updated_at',
    ];

    public function __construct(
        private Container $container,
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions,
        private ConnectionInterface $db
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        try {
            return $this->buildUnsafe();
        } catch (Throwable $e) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'plain_voting_enabled' => false,
                'provider' => [
                    'class_present' => false,
                    'extension_enabled' => false,
                    'vote_table_present' => false,
                    'vote_row_count' => null,
                ],
                'pusher' => [
                    'class_exists' => class_exists('Pusher', false),
                    'container_bound' => false,
                ],
                'activity' => [
                    'vote_state_table_present' => false,
                    'required_columns_present' => false,
                    'outbox_table_present' => false,
                    'outbox_terminal_at_present' => false,
                    'vote_state_row_count' => null,
                ],
                'likes' => [
                    'table_present' => false,
                    'row_count' => null,
                ],
                'settings' => [
                    'allow_self_votes' => null,
                    'auto_upvote_posts' => null,
                    'first_post_only' => null,
                    'up_votes_only' => null,
                    'rate_limit' => null,
                ],
                'permissions' => [
                    'guest_vote_posts' => false,
                    'member_vote_posts' => false,
                    'guest_ranking' => false,
                    'member_ranking' => false,
                    'guest_can_see_voters' => false,
                    'member_can_see_voters' => false,
                ],
                'safe_to_enable' => false,
                'blocking_reasons' => ['readiness_error'],
            ];
        }
    }

    /**
     * @return array{vote_state_table_present: bool, required_columns_present: bool, outbox_table_present: bool, outbox_terminal_at_present: bool, vote_state_row_count: int|null}
     */
    public function inspectActivitySchema(): array
    {
        try {
            $schema = $this->db->getSchemaBuilder();
            $voteStatePresent = $schema->hasTable('flatrate_vote_activity_state');
            $required = false;
            $rowCount = null;
            if ($voteStatePresent) {
                $columns = $schema->getColumnListing('flatrate_vote_activity_state');
                $missing = array_diff(self::REQUIRED_VOTE_STATE_COLUMNS, $columns);
                $required = $missing === [];
                $rowCount = (int) $this->db->table('flatrate_vote_activity_state')->count();
            }

            $outboxPresent = $schema->hasTable('flatrate_activity_outbox');
            $terminalAt = $outboxPresent && $schema->hasColumn('flatrate_activity_outbox', 'terminal_at');

            return [
                'vote_state_table_present' => $voteStatePresent,
                'required_columns_present' => $required,
                'outbox_table_present' => $outboxPresent,
                'outbox_terminal_at_present' => $terminalAt,
                'vote_state_row_count' => $rowCount,
            ];
        } catch (Throwable $e) {
            // Fail closed: schema inspection errors are unsafe.
            return [
                'vote_state_table_present' => false,
                'required_columns_present' => false,
                'outbox_table_present' => false,
                'outbox_terminal_at_present' => false,
                'vote_state_row_count' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUnsafe(): array
    {
        $providerClass = class_exists('FoF\\Gamification\\Listeners\\SaveVotesToDatabase');
        $providerEnabled = $this->extensions->isEnabled('fof-gamification');
        $pusherExists = class_exists('Pusher');
        $pusherBound = $this->container->bound('Pusher');

        $activity = $this->inspectActivitySchema();
        $likes = $this->inspectTable('post_likes');
        $votes = $this->inspectTable('post_votes');

        $settings = [
            'allow_self_votes' => $this->optionalBoolSetting('fof-gamification.allowSelfVotes'),
            'auto_upvote_posts' => $this->optionalBoolSetting('fof-gamification.autoUpvotePosts'),
            'first_post_only' => $this->optionalBoolSetting('fof-gamification.firstPostOnly'),
            'up_votes_only' => $this->optionalBoolSetting('fof-gamification.upVotesOnly'),
            'rate_limit' => $this->optionalBoolSetting('fof-gamification.rateLimit'),
        ];

        $permissions = [
            'guest_vote_posts' => $this->groupHasPermission(Group::GUEST_ID, 'discussion.votePosts')
                || $this->groupHasPermission(Group::GUEST_ID, 'discussion.vote'),
            'member_vote_posts' => $this->groupHasPermission(Group::MEMBER_ID, 'discussion.votePosts')
                || $this->groupHasPermission(Group::MEMBER_ID, 'discussion.vote'),
            'guest_ranking' => $this->groupHasPermission(Group::GUEST_ID, 'fof.gamification.viewRankingPage'),
            'member_ranking' => $this->groupHasPermission(Group::MEMBER_ID, 'fof.gamification.viewRankingPage'),
            'guest_can_see_voters' => $this->groupHasPermission(Group::GUEST_ID, 'discussion.canSeeVoters'),
            'member_can_see_voters' => $this->groupHasPermission(Group::MEMBER_ID, 'discussion.canSeeVoters'),
        ];

        $plainEnabled = (bool) $this->settings->get(VoteSafetyGate::SETTING_ENABLED);
        $blocking = [];

        if (! $providerClass) {
            $blocking[] = 'provider_absent';
        } elseif (! $providerEnabled) {
            $blocking[] = 'provider_disabled';
        }

        if ($pusherBound) {
            $blocking[] = 'pusher_bound';
        }
        if (! $activity['vote_state_table_present']) {
            $blocking[] = 'activity_vote_state_missing';
        } elseif (! $activity['required_columns_present']) {
            $blocking[] = 'activity_vote_state_schema_mismatch';
        }
        if (! $activity['outbox_table_present'] || ! $activity['outbox_terminal_at_present']) {
            $blocking[] = 'activity_outbox_missing';
        }

        if ($settings['allow_self_votes'] === true) {
            $blocking[] = 'self_votes_enabled';
        }
        if ($settings['auto_upvote_posts'] === true) {
            $blocking[] = 'auto_upvote_enabled';
        }
        if ($settings['first_post_only'] === true) {
            $blocking[] = 'first_post_only_enabled';
        }
        if ($settings['up_votes_only'] === true) {
            $blocking[] = 'upvotes_only_enabled';
        }
        if ($settings['rate_limit'] === false) {
            $blocking[] = 'rate_limit_disabled';
        }
        if ($permissions['guest_vote_posts']) {
            $blocking[] = 'guest_vote_permission';
        }
        if ($permissions['member_can_see_voters'] || $permissions['guest_can_see_voters']) {
            $blocking[] = 'ordinary_voter_identity_permission';
        }
        if ($providerEnabled && $votes['present'] !== true) {
            $blocking[] = 'provider_vote_table_missing';
        }
        if ($plainEnabled) {
            $blocking[] = 'vote_gate_already_open';
        }

        $safe = $providerClass
            && $providerEnabled
            && ! $pusherBound
            && $activity['vote_state_table_present']
            && $activity['required_columns_present']
            && $activity['outbox_table_present']
            && $activity['outbox_terminal_at_present']
            && $settings['allow_self_votes'] === false
            && $settings['auto_upvote_posts'] === false
            && $settings['first_post_only'] === false
            && $settings['up_votes_only'] === false
            && $settings['rate_limit'] === true
            && ! $permissions['guest_vote_posts']
            && ! $permissions['member_can_see_voters']
            && ! $permissions['guest_can_see_voters']
            && $votes['present'] === true
            && ! $plainEnabled;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plain_voting_enabled' => $plainEnabled,
            'provider' => [
                'class_present' => $providerClass,
                'extension_enabled' => $providerEnabled,
                'vote_table_present' => $votes['present'],
                'vote_row_count' => $votes['count'],
            ],
            'pusher' => [
                'class_exists' => $pusherExists,
                'container_bound' => $pusherBound,
            ],
            'activity' => [
                'vote_state_table_present' => $activity['vote_state_table_present'],
                'required_columns_present' => $activity['required_columns_present'],
                'outbox_table_present' => $activity['outbox_table_present'],
                'outbox_terminal_at_present' => $activity['outbox_terminal_at_present'],
                'vote_state_row_count' => $activity['vote_state_row_count'],
            ],
            'likes' => [
                'table_present' => $likes['present'],
                'row_count' => $likes['count'],
            ],
            'settings' => $settings,
            'permissions' => $permissions,
            'safe_to_enable' => $safe,
            'blocking_reasons' => $blocking,
        ];
    }

    private function optionalBoolSetting(string $key): ?bool
    {
        $raw = $this->settings->get($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $raw;
    }

    private function groupHasPermission(int $groupId, string $permission): bool
    {
        try {
            return Permission::query()
                ->where('group_id', $groupId)
                ->where('permission', $permission)
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{present: bool, count: int|null}
     */
    private function inspectTable(string $logical): array
    {
        try {
            $schema = $this->db->getSchemaBuilder();
            if (! $schema->hasTable($logical)) {
                return ['present' => false, 'count' => null];
            }

            return [
                'present' => true,
                'count' => (int) $this->db->table($logical)->count(),
            ];
        } catch (Throwable $e) {
            return ['present' => false, 'count' => null];
        }
    }
}
