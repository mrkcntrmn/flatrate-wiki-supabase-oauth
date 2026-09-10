<?php

namespace FlatRate\SupabaseOAuth\Activity;

/**
 * FlatRate HMAC v1 signer for forum→app activity ingest.
 * Canonical string matches wiki Node verifier and SSO bridge.
 */
final class HmacSigner
{
    public const PROTOCOL_VERSION = 1;

    public function sign(
        string $secret,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $rawBody
    ): string {
        $bodyHash = hash('sha256', $rawBody);
        $canonical = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $bodyHash,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    public function freshNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
