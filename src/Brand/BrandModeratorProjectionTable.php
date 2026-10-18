<?php

namespace FlatRate\SupabaseOAuth\Brand;

use Flarum\User\User;
use InvalidArgumentException;

/**
 * Projection with revision-ordered signed sync and a bounded freshness TTL.
 * Root application remains authoritative for Brand moderator approvals.
 */
final class BrandModeratorProjectionTable
{
    public const MAX_SYNC_AGE_SECONDS = 180;

    public function __construct(private $connection = null)
    {
    }

    public static function validBrandKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/D', $key);
    }

    public function isActiveId(int $userId, string $brandKey): bool
    {
        if ($userId < 1 || !self::validBrandKey($brandKey)) {
            return false;
        }

        try {
            $row = $this->connection()
                ->table('flatrate_brand_moderator_access')
                ->where('user_id', $userId)
                ->where('brand_key', $brandKey)
                ->first();
        } catch (\Throwable $e) {
            // Absent/unmigrated projection cannot grant moderation.
            return false;
        }

        if ($row === null || !self::strictActive($row->active ?? false)) {
            return false;
        }
        $synced = strtotime((string) ($row->synced_at ?? ''));
        if ($synced === false) {
            return false;
        }
        $age = time() - $synced;
        return $age >= -30 && $age <= self::MAX_SYNC_AGE_SECONDS;
    }

    public function syncId(int $userId, string $brandKey, bool $active, int $revision): bool
    {
        if ($userId < 1 || !self::validBrandKey($brandKey)
            || $revision < 1 || $revision === PHP_INT_MAX) {
            throw new InvalidArgumentException('invalid_brand_moderator_projection');
        }

        return $this->connection()->transaction(function () use ($userId, $brandKey, $active, $revision) {
            $db = $this->connection();
            $query = $db->table('flatrate_brand_moderator_access')
                ->where('user_id', $userId)
                ->where('brand_key', $brandKey);
            $existing = $query->lockForUpdate()->first();
            if ($existing && $revision <= (int) $existing->revision) {
                // Stale replay can never restore a revoked role or prolong TTL.
                return false;
            }

            $values = [
                'active' => $active ? 1 : 0,
                'revision' => $revision,
                'synced_at' => gmdate('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $query->update($values);
            } else {
                $db->table('flatrate_brand_moderator_access')->insert(
                    ['user_id' => $userId, 'brand_key' => $brandKey] + $values
                );
            }
            return true;
        });
    }

    private static function strictActive($raw): bool
    {
        return $raw === true || $raw === 1 || $raw === '1';
    }

    private function connection()
    {
        return $this->connection ?? (new User())->getConnection();
    }
}
