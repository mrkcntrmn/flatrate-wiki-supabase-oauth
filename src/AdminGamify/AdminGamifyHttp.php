<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared admin-gamify request validation and response headers.
 */
final class AdminGamifyHttp
{
    public const WINDOWS = ['today', '7d', '30d', 'current_month', 'all_time'];

    public const DEFAULT_WINDOW = '30d';

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /**
     * @return array{window: string, limit: int, cursor: mixed}
     */
    public static function parseQuery(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $window = isset($query['window']) ? trim((string) $query['window']) : self::DEFAULT_WINDOW;
        if (! in_array($window, self::WINDOWS, true)) {
            throw new AdminGamifyRequestException('admin_gamify_invalid_window', 400);
        }

        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit']) && $query['limit'] !== '') {
            if (! is_numeric($query['limit'])) {
                throw new AdminGamifyRequestException('admin_gamify_invalid_pagination', 400);
            }
            $limit = (int) $query['limit'];
            if ($limit < 1) {
                throw new AdminGamifyRequestException('admin_gamify_invalid_pagination', 400);
            }
            $limit = min($limit, self::MAX_LIMIT);
        }

        $cursor = null;
        if (isset($query['cursor']) && $query['cursor'] !== '') {
            $decoded = json_decode((string) $query['cursor'], true);
            if (! is_array($decoded)) {
                throw new AdminGamifyRequestException('admin_gamify_invalid_pagination', 400);
            }
            $cursor = $decoded;
        }

        return [
            'window' => $window,
            'limit' => $limit,
            'cursor' => $cursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $decoded = json_decode((string) $request->getBody(), true);
        if (! is_array($decoded)) {
            throw new AdminGamifyRequestException('admin_gamify_invalid_json', 400);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * @param array<string, mixed> $bridgeBody
     * @return array<string, mixed>
     */
    public static function sanitizeBridgePayload(array $bridgeBody): array
    {
        self::assertSanitizedValue($bridgeBody, '$');

        return $bridgeBody;
    }

    /**
     * Reject private identity fields. Bare UUID string values are rejected, but
     * launch_url strings that embed agtl_… base64url tickets are allowed.
     */
    private static function assertSanitizedValue(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $key => $child) {
                if (! $isList) {
                    $lower = strtolower((string) $key);
                    if (str_contains($lower, 'activity_subject')
                        || str_contains($lower, 'supabase_user')
                        || $lower === 'email'
                        || $lower === 'phone'
                        || str_contains($lower, 'claim_token')
                        || $lower === 'claim_token_hash'
                    ) {
                        throw new AdminGamifyRequestException('admin_gamify_identity_leak', 500);
                    }
                    self::assertSanitizedValue($child, $path.'.'.$key);
                } else {
                    self::assertSanitizedValue($child, $path.'['.$key.']');
                }
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if (preg_match('/^asub_[a-f0-9]{32}$/i', $value)) {
            throw new AdminGamifyRequestException('admin_gamify_identity_leak', 500);
        }

        // Exact UUID only — launch_url / launch_path with agtl_ tickets must pass.
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        )) {
            throw new AdminGamifyRequestException('admin_gamify_identity_leak', 500);
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $value) && preg_match('/claim|token|hash/i', $path)) {
            throw new AdminGamifyRequestException('admin_gamify_identity_leak', 500);
        }

        if (str_contains($value, '@') && preg_match('/email/i', $path)) {
            throw new AdminGamifyRequestException('admin_gamify_identity_leak', 500);
        }
    }
}
