<?php

declare(strict_types=1);

namespace FlatRate\SupabaseOAuth\Support;

use Flarum\Api\Client;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Extend\ApiResource;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Laminas\Diactoros\ServerRequest;
use Tobyz\JsonApiServer\Schema\Attribute;

// 1. FoF OAuth error stub
namespace FoF\OAuth\Errors;
if (! class_exists(AuthenticationException::class, false)) {
    class AuthenticationException extends \Exception {}
}

// 2. FoF OAuth extender stub
namespace FoF\OAuth\Extend;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
if (! class_exists(RegisterProvider::class, false)) {
    class RegisterProvider implements ExtenderInterface
    {
        public function __construct(protected string $providerClass) {}
        public function extend(Container $container, ?Extension $extension = null): void {}
    }
}

// 3. Flarum 1 Command stubs for RegisterUser & EditUser
namespace Flarum\User\Command;
use Flarum\Api\Client;
use Flarum\Foundation\ValidationException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\Guest;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;

if (! class_exists(RegisterUser::class, false)) {
    class RegisterUser
    {
        public function __construct(public $actor, public array $data) {}
    }
}

if (! class_exists(RegisterUserHandler::class, false)) {
    class RegisterUserHandler
    {
        public function __construct(protected ?Client $api = null) {}

        public function handle(RegisterUser $command): User
        {
            if ($this->api === null && function_exists('resolve')) {
                $this->api = resolve(Client::class);
            }

            $attributes = $command->data['attributes'] ?? ($command->data['data']['attributes'] ?? $command->data);
            $response = $this->api
                ->withoutErrorHandling()
                ->withParentRequest(new ServerRequest())
                ->withActor($command->actor ?: new Guest())
                ->withBody([
                    'data' => [
                        'type' => 'users',
                        'attributes' => $attributes,
                    ],
                ])
                ->post('/users');

            $payload = json_decode((string) $response->getBody(), true);
            $id = is_array($payload) ? ($payload['data']['id'] ?? null) : null;
            if ($response->getStatusCode() >= 400 || (! is_int($id) && ! is_string($id))) {
                throw new \RuntimeException('registration_failed');
            }

            return User::findOrFail($id);
        }
    }
}

if (! class_exists(EditUser::class, false)) {
    class EditUser
    {
        public function __construct(public int $userId, public $actor, public array $data) {}
    }
}

if (! class_exists(EditUserHandler::class, false)) {
    class EditUserHandler
    {
        public function __construct(protected ?Client $api = null) {}

        public function handle(EditUser $command): User
        {
            if ($this->api === null && function_exists('resolve')) {
                $this->api = resolve(Client::class);
            }

            $attributes = $command->data['attributes'] ?? ($command->data['data']['attributes'] ?? $command->data);
            $response = $this->api
                ->withErrorHandling()
                ->withParentRequest(new ServerRequest())
                ->withActor($command->actor)
                ->withBody([
                    'data' => [
                        'type' => 'users',
                        'id' => (string) $command->userId,
                        'attributes' => $attributes,
                    ],
                ])
                ->patch('/users/'.$command->userId);

            if ($response->getStatusCode() === 403) {
                throw new PermissionDeniedException();
            }

            if ($response->getStatusCode() === 422) {
                $payload = json_decode((string) $response->getBody(), true);
                $detail = $payload['errors'][0]['detail'] ?? ($payload['errors'][0]['code'] ?? 'invalid_nickname');
                throw new ValidationException(['nickname' => $detail]);
            }

            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException('edit_user_failed_'.$response->getStatusCode());
            }

            return User::findOrFail($command->userId);
        }
    }
}

// 4. Flarum 1 Serializer stubs
namespace Flarum\Api\Serializer;
if (! class_exists(AbstractSerializer::class, false)) {
    abstract class AbstractSerializer
    {
        public function __construct(protected $actor = null) {}
        public function getActor() { return $this->actor; }
    }
}
if (! class_exists(BasicUserSerializer::class, false)) {
    class BasicUserSerializer extends AbstractSerializer {}
}
if (! class_exists(UserSerializer::class, false)) {
    class UserSerializer extends BasicUserSerializer {}
}
if (! class_exists(PostSerializer::class, false)) {
    class PostSerializer extends AbstractSerializer {}
}
if (! class_exists(DiscussionSerializer::class, false)) {
    class DiscussionSerializer extends AbstractSerializer {}
}
if (! class_exists(ForumSerializer::class, false)) {
    class ForumSerializer extends AbstractSerializer {}
}

// 5. Flarum 1 ApiSerializer extender compat
namespace Flarum\Extend;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Extend\ApiResource;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Tobyz\JsonApiServer\Schema\Attribute;

if (! class_exists(ApiSerializer::class, false)) {
    class ApiSerializer implements ExtenderInterface
    {
        protected array $attributesCallbacks = [];
        protected array $relationships = [];

        public function __construct(protected string $serializerClass) {}

        public function attributes(callable|string $callback): self
        {
            $this->attributesCallbacks[] = $callback;
            return $this;
        }

        public function relationship(string $name, callable|string $callback): self
        {
            $this->relationships[$name] = $callback;
            return $this;
        }

        public function extend(Container $container, ?Extension $extension = null): void
        {
            if (! class_exists(UserResource::class)) {
                return;
            }

            if (is_a($this->serializerClass, BasicUserSerializer::class, true)) {
                $callbacks = $this->attributesCallbacks;
                (new ApiResource(UserResource::class))
                    ->field('avatarUrl', fn ($field) => $field->get(fn ($user, Context $c) => $c->getActor()->isGuest() ? null : $user->avatar_url))
                    ->field('username', fn ($field) => $field->get(fn ($user, Context $c) => $c->getActor()->isGuest() ? (string) ($user->username ?? '') : (string) $user->username))
                    ->field('displayName', fn ($field) => $field->get(fn ($user, Context $c) => $c->getActor()->isGuest() ? (string) ($user->username ?? '') : $user->display_name))
                    ->field('slug', fn ($field) => $field->get(fn ($user, Context $c) => $c->getActor()->isGuest() ? (string) ($user->username ?? '') : $container->make(\Flarum\Http\SlugManager::class)->forResource(\Flarum\User\User::class)->toSlug($user)))
                    ->fields(fn () => [
                        Attribute::make('flatRateMemberNumber')
                            ->visible(fn ($user, Context $c) => ! $c->getActor()->isGuest())
                            ->get(fn ($user, Context $c) => self::resolveUserAttr($container, $callbacks, $user, $c, 'flatRateMemberNumber')),
                        Attribute::make('flatRateDisplayMode')
                            ->visible(fn ($user, Context $c) => ! $c->getActor()->isGuest() && (int) $c->getActor()->id === (int) $user->id)
                            ->get(fn ($user, Context $c) => self::resolveUserAttr($container, $callbacks, $user, $c, 'flatRateDisplayMode')),
                        Attribute::make('flatRateCustomDisplayName')
                            ->visible(fn ($user, Context $c) => ! $c->getActor()->isGuest() && (int) $c->getActor()->id === (int) $user->id)
                            ->get(fn ($user, Context $c) => self::resolveUserAttr($container, $callbacks, $user, $c, 'flatRateCustomDisplayName')),
                        Attribute::make('flatRateOwnerDashboard')
                            ->visible(fn ($user, Context $c) => ! $c->getActor()->isGuest() && (int) $c->getActor()->id === (int) $user->id)
                            ->get(fn ($user, Context $c) => self::resolveUserAttr($container, $callbacks, $user, $c, 'flatRateOwnerDashboard')),
                        Attribute::make('isFlatRateBetaTester')
                            ->visible(fn ($user, Context $c) => ! $c->getActor()->isGuest() && ((int) $c->getActor()->id === (int) $user->id || $c->getActor()->isAdmin()))
                            ->get(fn ($user, Context $c) => self::resolveUserAttr($container, $callbacks, $user, $c, 'isFlatRateBetaTester')),
                    ])
                    ->extend($container, $extension);
            }

            if (is_a($this->serializerClass, PostSerializer::class, true)) {
                $callbacks = $this->attributesCallbacks;
                (new ApiResource(PostResource::class))
                    ->fields(fn () => [
                        Attribute::make('flatrateHelpfulCount')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'flatrateHelpfulCount')),
                        Attribute::make('flatrateViewerMarkedHelpful')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'flatrateViewerMarkedHelpful')),
                        Attribute::make('flatrateCanMarkHelpful')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'flatrateCanMarkHelpful')),
                        Attribute::make('flatrateIsAcceptedSolution')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'flatrateIsAcceptedSolution')),
                        Attribute::make('flatrateCanAcceptSolution')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'flatrateCanAcceptSolution')),
                        Attribute::make('votes')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'votes')),
                        Attribute::make('canVote')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'canVote')),
                        Attribute::make('hasUpvoted')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'hasUpvoted')),
                        Attribute::make('hasDownvoted')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'hasDownvoted')),
                        Attribute::make('canSeeVotes')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'canSeeVotes')),
                        Attribute::make('flatrateVoteGateEnabled')->get(fn ($p, Context $c) => self::resolvePostAttr($container, $callbacks, $p, $c, 'flatrateVoteGateEnabled')),
                    ])
                    ->extend($container, $extension);
            }

            if (is_a($this->serializerClass, DiscussionSerializer::class, true)) {
                $callbacks = $this->attributesCallbacks;
                (new ApiResource(DiscussionResource::class))
                    ->fields(fn () => [
                        Attribute::make('votes')->get(fn ($d, Context $c) => self::resolveDiscussionAttr($container, $callbacks, $d, $c, 'votes')),
                        Attribute::make('flatrateDiscussionVoteCount')->get(fn ($d, Context $c) => self::resolveDiscussionAttr($container, $callbacks, $d, $c, 'flatrateDiscussionVoteCount')),
                        Attribute::make('hasUpvoted')->get(fn ($d, Context $c) => self::resolveDiscussionAttr($container, $callbacks, $d, $c, 'hasUpvoted')),
                        Attribute::make('canVote')->get(fn ($d, Context $c) => self::resolveDiscussionAttr($container, $callbacks, $d, $c, 'canVote')),
                        Attribute::make('canSeeVotes')->get(fn ($d, Context $c) => self::resolveDiscussionAttr($container, $callbacks, $d, $c, 'canSeeVotes')),
                    ])
                    ->extend($container, $extension);
            }

            if (is_a($this->serializerClass, ForumSerializer::class, true)) {
                $callbacks = $this->attributesCallbacks;
                (new ApiResource(ForumResource::class))
                    ->fields(fn () => [
                        Attribute::make('flatrateBrandVoteTotals')->get(fn ($m, Context $c) => self::resolveForumAttr($container, $callbacks, $m, $c, 'flatrateBrandVoteTotals')),
                    ])
                    ->extend($container, $extension);
            }
        }

        protected static function resolveUserAttr(Container $container, array $callbacks, $user, Context $context, string $key)
        {
            $serializer = new class($context->getActor()) extends BasicUserSerializer {};
            $attrs = [];
            foreach ($callbacks as $cb) {
                $fn = is_string($cb) ? $container->make($cb) : $cb;
                $attrs = $fn($serializer, $user, $attrs);
            }
            return $attrs[$key] ?? null;
        }

        protected static function resolvePostAttr(Container $container, array $callbacks, $post, Context $context, string $key)
        {
            $serializer = new class($context->getActor()) extends PostSerializer {};
            $attrs = [];
            foreach ($callbacks as $cb) {
                $fn = is_string($cb) ? $container->make($cb) : $cb;
                $attrs = $fn($serializer, $post, $attrs);
            }
            return $attrs[$key] ?? null;
        }

        protected static function resolveDiscussionAttr(Container $container, array $callbacks, $discussion, Context $context, string $key)
        {
            $serializer = new class($context->getActor()) extends DiscussionSerializer {};
            $attrs = [];
            foreach ($callbacks as $cb) {
                $fn = is_string($cb) ? $container->make($cb) : $cb;
                $attrs = $fn($serializer, $discussion, $attrs);
            }
            return $attrs[$key] ?? null;
        }

        protected static function resolveForumAttr(Container $container, array $callbacks, $model, Context $context, string $key)
        {
            $serializer = new class($context->getActor()) extends ForumSerializer {};
            $attrs = [];
            foreach ($callbacks as $cb) {
                $fn = is_string($cb) ? $container->make($cb) : $cb;
                $attrs = $fn($serializer, $model, $attrs);
            }
            return $attrs[$key] ?? null;
        }
    }
}
