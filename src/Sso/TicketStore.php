<?php

namespace FlatRate\SupabaseOAuth\Sso;

use Flarum\User\User;

final class TicketStore
{
    public const TTL_SECONDS = 45;

    public function issue(User $user, string $returnTo): string
    {
        $returnTo = $this->validateReturnTo($returnTo);
        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ticketHash = hash('sha256', $ticket);
        $db = $user->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $db->table('flatrate_sso_tickets')->where('expires_at', '<', $now)->delete();
        $db->table('flatrate_sso_tickets')->insert([
            'ticket_hash' => $ticketHash,
            'user_id' => $user->id,
            'return_to' => $returnTo,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            'consumed_at' => null,
            'created_at' => $now,
        ]);

        return $ticket;
    }

    /**
     * @return array{user: User, returnTo: string}|null
     */
    public function consume(string $ticket): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{40,128}$/', $ticket)) {
            return null;
        }

        $ticketHash = hash('sha256', $ticket);
        $db = (new User())->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $result = $db->transaction(function () use ($db, $ticketHash, $now) {
            $row = $db->table('flatrate_sso_tickets')
                ->where('ticket_hash', $ticketHash)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->consumed_at !== null || $row->expires_at <= $now) {
                return null;
            }

            $updated = $db->table('flatrate_sso_tickets')
                ->where('id', $row->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            if ($updated !== 1) {
                return null;
            }

            return [
                'user_id' => (int) $row->user_id,
                'return_to' => (string) $row->return_to,
            ];
        });

        if (! $result) {
            return null;
        }

        $user = User::find($result['user_id']);
        if (! $user) {
            return null;
        }

        return [
            'user' => $user,
            'returnTo' => $this->validateReturnTo($result['return_to']),
        ];
    }

    public function validateReturnTo(?string $value): string
    {
        $returnTo = trim((string) $value);
        if ($returnTo === '') {
            return '/';
        }

        if (
            strlen($returnTo) > 2048
            || ! str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_contains($returnTo, '://')
            || str_contains($returnTo, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $returnTo)
        ) {
            throw new SsoException('invalid_return_to', 400);
        }

        return $returnTo;
    }
}
