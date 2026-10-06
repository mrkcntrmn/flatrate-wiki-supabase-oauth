<?php

namespace FlatRate\SupabaseOAuth\Beta;

/**
 * Private projection rows. Missing row is inactive. Revocation keeps the row.
 */
final class BetaTesterProjectionTable
{
    public function __construct(private $connection = null)
    {
    }

    public function isActiveId(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $row = $this->connection()
            ->table('flatrate_beta_tester_access')
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return false;
        }

        return self::storedActive($row->active ?? false);
    }

    public function syncId(int $userId, bool $active): bool
    {
        if ($userId < 1) {
            return false;
        }

        $db = $this->connection();
        $now = gmdate('Y-m-d H:i:s');
        $row = $db->table('flatrate_beta_tester_access')->where('user_id', $userId)->first();
        $previous = $row === null ? false : self::storedActive($row->active ?? false);

        if ($row === null) {
            $db->table('flatrate_beta_tester_access')->insert([
                'user_id' => $userId,
                'active' => $active ? 1 : 0,
                'synced_at' => $now,
            ]);
        } else {
            $db->table('flatrate_beta_tester_access')->where('user_id', $userId)->update([
                'active' => $active ? 1 : 0,
                'synced_at' => $now,
            ]);
        }

        return $previous !== $active;
    }

    public function syncedAt(int $userId): ?string
    {
        $row = $this->connection()
            ->table('flatrate_beta_tester_access')
            ->where('user_id', $userId)
            ->first();

        if ($row === null || ! isset($row->synced_at)) {
            return null;
        }

        return (string) $row->synced_at;
    }

    private static function storedActive(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function connection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        return (new \Flarum\User\User())->getConnection();
    }
}
