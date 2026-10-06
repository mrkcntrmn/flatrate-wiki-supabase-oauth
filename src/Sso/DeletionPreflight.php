<?php

namespace FlatRate\SupabaseOAuth\Sso;

use Illuminate\Database\ConnectionInterface;
use Throwable;

final class DeletionPreflight
{
    public const CONTRACT_VERSION = 'account_deletion_community_preflight_v1';

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function inspect(string $sub): array
    {
        $sub = trim($sub);
        if ($sub === '') {
            throw new SsoException('invalid_subject', 400);
        }

        try {
            $schema = $this->db->getSchemaBuilder();

            if (! $schema->hasTable('login_providers')) {
                throw new SsoException('community_deletion_preflight_unavailable', 503);
            }

            $userId = $this->db->table('login_providers')
                ->where('provider', 'flatrate')
                ->where('identifier', $sub)
                ->value('user_id');

            if ($userId === null) {
                return $this->notLinked();
            }

            $userId = (int) $userId;
            if ($userId <= 0) {
                throw new SsoException('community_deletion_identity_invalid', 503);
            }

            $rootAdminBlocked = $userId === 1;
            $userExists = $schema->hasTable('users')
                && $this->db->table('users')->where('id', $userId)->exists();

            if (! $userExists) {
                throw new SsoException('community_deletion_identity_inconsistent', 503);
            }

            $directMessagesPresent = $this->hasColumn($schema, 'messages', 'user_id');
            $directMembershipPresent = $this->hasColumn($schema, 'conversation_user', 'user_id');
            $liveMessagesPresent = $this->hasColumn($schema, 'neonchat_messages', 'user_id');
            $liveMembershipPresent = $this->hasColumn($schema, 'neonchat_chat_user', 'user_id');

            return [
                'contract_version' => self::CONTRACT_VERSION,
                'linked' => true,
                'root_admin_block' => $rootAdminBlocked,
                'identity_integrity' => 'linked_user_present',
                'community_cleanup_required' => true,
                'destructive_execute_supported' => false,
                'public_content_policy' => 'retain_detach_author',
                'private_direct_messages_policy' => 'delete_authored_private_content',
                'private_live_chat_policy' => 'delete_authored_private_content',
                'media_policy_state' => 'requires_deployed_extension_qualification',
                'schema' => [
                    'direct_messages_present' => $directMessagesPresent,
                    'live_chat_present' => $liveMessagesPresent || $liveMembershipPresent,
                ],
                'counts' => [
                    'public_posts' => $this->countByUser($schema, 'posts', 'user_id', $userId),
                    'public_discussions' => $this->countByUser($schema, 'discussions', 'user_id', $userId),
                    'access_tokens' => $this->countByUser($schema, 'access_tokens', 'user_id', $userId),
                    'login_provider_links' => $this->countByUser($schema, 'login_providers', 'user_id', $userId),
                    'sso_tickets' => $this->countByUser($schema, 'flatrate_sso_tickets', 'user_id', $userId),
                    'direct_messages_authored' => $directMessagesPresent
                        ? $this->countByUser($schema, 'messages', 'user_id', $userId)
                        : 0,
                    'direct_conversation_memberships' => $directMembershipPresent
                        ? $this->countByUser($schema, 'conversation_user', 'user_id', $userId)
                        : 0,
                    'live_chat_messages_authored' => $liveMessagesPresent
                        ? $this->countByUser($schema, 'neonchat_messages', 'user_id', $userId)
                        : 0,
                    'live_chat_memberships' => $liveMembershipPresent
                        ? $this->countByUser($schema, 'neonchat_chat_user', 'user_id', $userId)
                        : 0,
                ],
            ];
        } catch (SsoException $error) {
            throw $error;
        } catch (Throwable) {
            throw new SsoException('community_deletion_preflight_unavailable', 503);
        }
    }

    private function notLinked(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'linked' => false,
            'root_admin_block' => false,
            'identity_integrity' => 'not_linked',
            'community_cleanup_required' => false,
            'destructive_execute_supported' => false,
            'public_content_policy' => 'not_applicable',
            'private_direct_messages_policy' => 'not_applicable',
            'private_live_chat_policy' => 'not_applicable',
            'media_policy_state' => 'not_applicable',
            'schema' => [
                'direct_messages_present' => false,
                'live_chat_present' => false,
            ],
            'counts' => [
                'public_posts' => 0,
                'public_discussions' => 0,
                'access_tokens' => 0,
                'login_provider_links' => 0,
                'sso_tickets' => 0,
                'direct_messages_authored' => 0,
                'direct_conversation_memberships' => 0,
                'live_chat_messages_authored' => 0,
                'live_chat_memberships' => 0,
            ],
        ];
    }

    private function hasColumn($schema, string $table, string $column): bool
    {
        return $schema->hasTable($table) && $schema->hasColumn($table, $column);
    }

    private function countByUser($schema, string $table, string $column, int $userId): int
    {
        if (! $this->hasColumn($schema, $table, $column)) {
            return 0;
        }

        return (int) $this->db->table($table)
            ->where($column, $userId)
            ->count();
    }
}
