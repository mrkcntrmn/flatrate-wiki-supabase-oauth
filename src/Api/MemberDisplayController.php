<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Identity\CustomNicknameValidator;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayException;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayService;
use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use FlatRate\SupabaseOAuth\Identity\MemberProfileStore;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PATCH /api/flatrate/member-display
 *
 * Actor may change only their own display mode. Client member_number is ignored.
 */
final class MemberDisplayController implements RequestHandlerInterface
{
    public function __construct(
        private MemberProfileStore $profiles,
        private MemberDisplayService $display,
        private CustomNicknameValidator $nicknames
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        try {
            $body = $this->payload($request);
            unset($body['member_number']);

            $mode = is_string($body['mode'] ?? null) ? $body['mode'] : '';
            $nickname = array_key_exists('nickname', $body) ? $body['nickname'] : null;
            if ($nickname !== null && ! is_string($nickname)) {
                throw new MemberDisplayException('invalid_nickname');
            }

            $user = $actor->getConnection()->transaction(function () use ($actor, $mode, $nickname) {
                /** @var User|null $user */
                $user = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if (! $user) {
                    throw new MemberDisplayException('member_profile_missing', 404);
                }

                $profile = $this->profiles->requireFor($user);

                if ($mode === MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER) {
                    $this->display->applyMemberNumber($user, $profile);
                } elseif ($mode === MemberIdentity::DISPLAY_MODE_CUSTOM) {
                    if (is_string($nickname) && ! $this->isRetainedRestore($profile, $nickname)) {
                        $this->nicknames->assertAcceptable($nickname, (int) $user->id);
                    }
                    $this->display->applyTrustedCustom($user, $profile, $nickname);
                } else {
                    throw new MemberDisplayException('invalid_display_mode');
                }

                $profile->updated_at = date('Y-m-d H:i:s');
                $user->save();
                $profile->save();

                return $user;
            });

            $profile = $this->profiles->requireFor($user);

            return $this->json([
                'data' => [
                    'type' => 'users',
                    'id' => (string) $user->id,
                    'attributes' => [
                        'nickname' => $user->nickname,
                        'flatRateMemberNumber' => (int) $profile->member_number,
                        'flatRateMemberNickname' => MemberIdentity::nickname((int) $profile->member_number),
                        'flatRateNicknameMode' => $profile->display_mode,
                        'flatRateCustomNickname' => $profile->custom_nickname,
                        'flatRateCustomNicknameOrigin' => $profile->custom_nickname_origin,
                    ],
                ],
            ]);
        } catch (MemberDisplayException $error) {
            return $this->json(['errors' => [['code' => $error->errorCode]]], $error->statusCode);
        }
    }

    private function isRetainedRestore(object $profile, string $nickname): bool
    {
        $retained = trim((string) ($profile->custom_nickname ?? ''));

        return $retained !== '' && strcasecmp(trim($nickname), $retained) === 0;
    }

    private function payload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
