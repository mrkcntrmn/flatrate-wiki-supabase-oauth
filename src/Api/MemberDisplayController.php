<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Identity\MemberDisplayException;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayPolicy;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayService;
use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use FlatRate\SupabaseOAuth\Identity\MemberProfileStore;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\Command\EditUser;
use Flarum\User\Command\EditUserHandler;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PATCH /api/flatrate/member-display
 *
 * Actor may change only their own display mode. Client member_number is ignored.
 * Ordinary custom nicknames go through EditUser so Nicknames validation,
 * editNickname permission, and Saving/UserValidator remain authoritative.
 *
 * MEMBER_DISPLAY_REQUIRES_EDIT_NICKNAME_PERMISSION=true
 */
final class MemberDisplayController implements RequestHandlerInterface
{
    public function __construct(
        private MemberProfileStore $profiles,
        private MemberDisplayService $display,
        private MemberDisplayPolicy $policy,
        private EditUserHandler $editUser
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

                $this->policy->assertActorMayChangeDisplay($actor, $user);
                $profile = $this->profiles->requireFor($user);

                if ($mode === MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER) {
                    $this->policy->assertCanonicalAssignable($user, [$this, 'identityOccupiedByOther']);
                    $this->display->applyMemberNumber($user, $profile);
                    $profile->updated_at = date('Y-m-d H:i:s');
                    $user->save();
                    $profile->save();

                    return $user;
                }

                if ($mode !== MemberIdentity::DISPLAY_MODE_CUSTOM) {
                    throw new MemberDisplayException('invalid_display_mode');
                }

                $action = $this->policy->resolveCustomAction($profile, $nickname);
                if ($action['kind'] === 'trusted_restore') {
                    $this->display->restoreCustom($user, $profile);
                    $profile->updated_at = date('Y-m-d H:i:s');
                    $user->save();
                    $profile->save();

                    return $user;
                }

                return $this->editUser->handle(new EditUser($user->id, $actor, [
                    'attributes' => [
                        'nickname' => $action['nickname'],
                    ],
                ]));
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
        } catch (PermissionDeniedException $error) {
            return $this->json(['errors' => [['code' => 'permission_denied']]], 403);
        } catch (ValidationException $error) {
            return $this->json(['errors' => [['code' => 'invalid_nickname']]], 422);
        }
    }

    public function identityOccupiedByOther(string $value, int $userId): bool
    {
        $needle = strtolower($value);

        return User::query()
            ->where('id', '!=', $userId)
            ->where(function ($query) use ($needle) {
                $query->whereRaw('LOWER(nickname) = ?', [$needle])
                    ->orWhereRaw('LOWER(username) = ?', [$needle]);
            })
            ->exists();
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
