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
        $encoded = json_encode($bridgeBody);
        if (! is_string($encoded)) {
            throw new AdminGamifyRequestException('admin_gamify_sanitize_failed', 500);
        }
        if (preg_match('/"activity_subject_id"\s*:/', $encoded)
            || preg_match('/"supabase_user_id"\s*:/', $encoded)
            || preg_match('/"claim_token_hash"\s*:/', $encoded)
            || preg_match('/"email"\s*:/', $encoded)
            || preg_match('/"phone"\s*:/', $encoded)
            || preg_match('/asub_[a-f0-9]{32}/i', $encoded)
        ) {
            throw new AdminGamifyRequestException('admin_gamify_identity_leak', 500);
        }

        return $bridgeBody;
    }
}
