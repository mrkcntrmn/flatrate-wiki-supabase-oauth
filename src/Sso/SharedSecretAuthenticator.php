<?php

namespace FlatRate\SupabaseOAuth\Sso;

use Flarum\User\User;
use Illuminate\Database\QueryException;
use Psr\Http\Message\ServerRequestInterface;

final class SharedSecretAuthenticator
{
    private const MAX_CLOCK_SKEW_SECONDS = 60;
    private const NONCE_TTL_SECONDS = 120;

    public function authenticate(ServerRequestInterface $request): void
    {
        $secret = trim((string) getenv('FORUM_SSO_SHARED_SECRET'));
        if (strlen($secret) < 32) {
            throw new SsoException('forum_sso_not_configured', 503);
        }

        $timestampRaw = trim($request->getHeaderLine('X-FlatRate-Timestamp'));
        $nonce = trim($request->getHeaderLine('X-FlatRate-Nonce'));
        $signature = trim($request->getHeaderLine('X-FlatRate-Signature'));

        if (! preg_match('/^\d{10}$/', $timestampRaw)) {
            throw new SsoException('invalid_sso_timestamp', 401);
        }

        $timestamp = (int) $timestampRaw;
        if (abs(time() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            throw new SsoException('stale_sso_request', 401);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{22,128}$/', $nonce)) {
            throw new SsoException('invalid_sso_nonce', 401);
        }

        if (str_starts_with($signature, 'v1=')) {
            $signature = substr($signature, 3);
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            throw new SsoException('invalid_sso_signature', 401);
        }

        $body = (string) $request->getBody();
        $canonical = implode("\n", [
            $timestampRaw,
            $nonce,
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            hash('sha256', $body),
        ]);
        $expected = hash_hmac('sha256', $canonical, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            throw new SsoException('invalid_sso_signature', 401);
        }

        $db = (new User())->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + self::NONCE_TTL_SECONDS);
        $nonceHash = hash('sha256', $nonce);

        // Bound table growth without making correctness depend on cleanup.
        $db->table('flatrate_sso_nonces')->where('expires_at', '<', $now)->delete();

        try {
            $db->table('flatrate_sso_nonces')->insert([
                'nonce_hash' => $nonceHash,
                'expires_at' => $expires,
                'created_at' => $now,
            ]);
        } catch (QueryException) {
            // nonce_hash is the primary key, so a duplicate is an authenticated
            // request replay even when the HMAC itself is otherwise valid.
            throw new SsoException('replayed_sso_request', 401);
        }
    }
}
