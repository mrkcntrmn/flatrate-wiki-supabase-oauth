<?php

namespace FlatRate\SupabaseOAuth\Sso;

use FlatRate\SupabaseOAuth\Beta\LinkedFlatRateUserResolver;
use FlatRate\SupabaseOAuth\Brand\BrandModeratorProjectionStore;
use FlatRate\SupabaseOAuth\Brand\BrandModeratorProjectionTable;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Internal server-to-server projection refresh.
 *
 * The root app MUST authenticate OWNER_ROOT/authorized role management and
 * check its own approved assignments before sending signed sync. This endpoint
 * never accepts a browser session, invite token or pending approval as a grant.
 */
final class BrandModeratorSyncController implements RequestHandlerInterface
{
    public const ENABLED_SETTING = 'flatrate-brand-moderator.signed_sync_enabled';

    public function __construct(
        private SharedSecretAuthenticator $authenticator,
        private LinkedFlatRateUserResolver $linkedUsers,
        private BrandModeratorProjectionStore $projection,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->authenticator->authenticate($request);
            if ($this->settings->get(self::ENABLED_SETTING) !== '1') {
                throw new SsoException('brand_moderator_sync_disabled', 503);
            }

            $body = $this->payload($request);
            $sub = $body['sub'] ?? null;
            $brandKey = $body['brand_key'] ?? null;
            $capability = $body['capability'] ?? null;
            $active = $body['active'] ?? null;
            $revision = $body['revision'] ?? null;

            if (!is_string($sub) || trim($sub) === '' || strlen($sub) > 512) {
                throw new SsoException('invalid_subject', 400);
            }
            if (!is_string($brandKey)
                || !BrandModeratorProjectionTable::validBrandKey($brandKey)
                || $capability !== 'moderate_live'
                || !is_bool($active)
                || !is_int($revision) || $revision < 1 || $revision === PHP_INT_MAX) {
                throw new SsoException('invalid_brand_moderator_payload', 400);
            }

            $user = $this->linkedUsers->findBySubject($sub);
            if (!$user) {
                // Do not invent/provision accounts during an authorization sync.
                return $this->json(['ok' => true, 'linked' => false, 'changed' => false]);
            }

            $changed = $this->projection->sync($user, $brandKey, $active, $revision);
            return $this->json(['ok' => true, 'linked' => true, 'changed' => $changed]);
        } catch (SsoException $error) {
            return $this->json(['error' => $error->errorCode], $error->statusCode);
        } catch (\Throwable $error) {
            // No database/internal detail leaks on API or uncertain updates.
            return $this->json(['error' => 'brand_moderator_sync_unavailable'], 503);
        }
    }

    private function payload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }
        $decoded = json_decode((string) $request->getBody(), true);
        if (!is_array($decoded)) {
            throw new SsoException('invalid_json', 400);
        }
        return $decoded;
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
