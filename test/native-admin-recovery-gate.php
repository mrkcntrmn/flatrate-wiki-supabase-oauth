<?php

declare(strict_types=1);

namespace Psr\Http\Message {
    interface ResponseInterface
    {
    }

    interface UriInterface
    {
        public function getPath(): string;
    }

    interface ServerRequestInterface
    {
        public function getMethod(): string;

        public function getUri(): UriInterface;

        public function getParsedBody(): mixed;
    }
}

namespace Psr\Http\Server {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    interface RequestHandlerInterface
    {
        public function handle(ServerRequestInterface $request): ResponseInterface;
    }

    interface MiddlewareInterface
    {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
    }
}

namespace Flarum\User {
    class User
    {
        public function __construct(private bool $admin)
        {
        }

        public function isAdmin(): bool
        {
            return $this->admin;
        }
    }

    class UserRepository
    {
        public ?User $found = null;

        public function findByIdentification(string $identification): ?User
        {
            return $this->found;
        }
    }
}

namespace Flarum\Http {
    use Flarum\User\User;

    class RequestUtil
    {
        public static ?User $actor = null;

        public static function getActor(object $request): User
        {
            return self::$actor ?? new User(false);
        }
    }
}

namespace Laminas\Diactoros\Response {
    use Psr\Http\Message\ResponseInterface;

    class JsonResponse implements ResponseInterface
    {
        public function __construct(public mixed $payload, public int $status)
        {
        }
    }
}

namespace {
    use Flarum\Http\RequestUtil;
    use Flarum\User\User;
    use Flarum\User\UserRepository;
    use FlatRate\SupabaseOAuth\Auth\NativeAdminRecoveryGate;
    use FlatRate\SupabaseOAuth\Middleware\RequireFlatRateIdentity;
    use Laminas\Diactoros\Response\JsonResponse;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\UriInterface;
    use Psr\Http\Server\RequestHandlerInterface;

    require dirname(__DIR__).'/src/Auth/NativeAdminRecoveryGate.php';
    require dirname(__DIR__).'/src/Middleware/RequireFlatRateIdentity.php';

    $failures = [];

    function expect(bool $condition, string $name): void
    {
        global $failures;
        if (!$condition) {
            $failures[] = $name;
        }
    }

    final class RecoveryUri implements UriInterface
    {
        public function __construct(private string $path)
        {
        }

        public function getPath(): string
        {
            return $this->path;
        }
    }

    final class RecoveryRequest implements ServerRequestInterface
    {
        public function __construct(
            private string $method,
            private string $path,
            private mixed $body
        ) {
        }

        public function getMethod(): string
        {
            return $this->method;
        }

        public function getUri(): UriInterface
        {
            return new RecoveryUri($this->path);
        }

        public function getParsedBody(): mixed
        {
            return $this->body;
        }
    }

    final class RecoveryHandler implements RequestHandlerInterface
    {
        public int $calls = 0;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->calls++;

            return new JsonResponse(['continued' => true], 200);
        }
    }

    $gate = new NativeAdminRecoveryGate();
    expect($gate->allowsPasswordLogin(true, true) === true, 'ADMIN_PASSWORD_LOGIN_ALLOWED');
    expect($gate->allowsPasswordLogin(true, false) === false, 'MEMBER_PASSWORD_LOGIN_REFUSED');
    expect($gate->allowsPasswordLogin(false, false) === false, 'UNKNOWN_IDENTIFICATION_REFUSED');
    expect($gate->allowsPasswordLogin(false, true) === false, 'ADMIN_FLAG_WITHOUT_USER_REFUSED');
    expect($gate->allowsNativeSignup(true, null) === true, 'ADMIN_SIGNUP_WITHOUT_TOKEN_ALLOWED');
    expect($gate->allowsNativeSignup(false, null) === false, 'MEMBER_SIGNUP_WITHOUT_TOKEN_REFUSED');
    expect($gate->allowsNativeSignup(false, '') === false, 'BLANK_REGISTRATION_TOKEN_REFUSED');
    expect($gate->allowsNativeSignup(false, '   ') === false, 'WHITESPACE_REGISTRATION_TOKEN_REFUSED');
    expect($gate->allowsNativeSignup(false, ['token']) === false, 'NON_STRING_REGISTRATION_TOKEN_REFUSED');
    expect($gate->allowsNativeSignup(false, 'oauth-registration-token') === true, 'OAUTH_REGISTRATION_TOKEN_ALLOWED');

    $users = new UserRepository();
    $middleware = new RequireFlatRateIdentity($users);

    function post(RequireFlatRateIdentity $middleware, UserRepository $users, ?User $actor, string $path, mixed $body): array
    {
        $users->found = $body['found'] ?? null;
        unset($body['found']);
        RequestUtil::$actor = $actor;
        $handler = new RecoveryHandler();
        $response = $middleware->process(new RecoveryRequest('POST', $path, $body), $handler);

        return [$response, $handler->calls];
    }

    [$adminLogin, $adminCalls] = post($middleware, $users, new User(false), '/login', [
        'identification' => 'recovery-admin',
        'password' => 'not-asserted',
        'found' => new User(true),
    ]);
    expect($adminCalls === 1 && $adminLogin->status === 200, 'ADMIN_LOGIN_REACHES_NEXT_HANDLER');

    [$memberLogin, $memberCalls] = post($middleware, $users, new User(false), '/api/token', [
        'identification' => 'member',
        'password' => 'not-asserted',
        'found' => new User(false),
    ]);
    expect($memberCalls === 0 && $memberLogin->status === 403, 'MEMBER_TOKEN_LOGIN_REFUSED');
    expect(($memberLogin->payload['errors'][0]['code'] ?? '') === 'flatrate_sso_required', 'MEMBER_TOKEN_LOGIN_CODE');

    [$unknownLogin, $unknownCalls] = post($middleware, $users, new User(false), '/token', [
        'identification' => 'missing',
        'password' => 'not-asserted',
        'found' => null,
    ]);
    expect($unknownCalls === 0 && $unknownLogin->status === 403, 'UNKNOWN_LOGIN_REFUSED');

    [$blankLogin, $blankCalls] = post($middleware, $users, new User(false), '/login/', [
        'identification' => '   ',
        'found' => new User(true),
    ]);
    expect($blankCalls === 0 && $blankLogin->status === 403, 'BLANK_IDENTIFICATION_REFUSED');

    $readHandler = new RecoveryHandler();
    $users->found = new User(false);
    $read = $middleware->process(new RecoveryRequest('GET', '/login', []), $readHandler);
    expect($readHandler->calls === 1 && $read->status === 200, 'GET_LOGIN_NOT_BLOCKED');

    [$memberSignup, $memberSignupCalls] = post($middleware, $users, new User(false), '/api/users', [
        'data' => ['attributes' => []],
    ]);
    expect($memberSignupCalls === 0 && $memberSignup->status === 403, 'PUBLIC_SIGNUP_REFUSED');

    [$oauthSignup, $oauthSignupCalls] = post($middleware, $users, new User(false), '/api/users', [
        'data' => ['attributes' => ['token' => 'registration-token']],
    ]);
    expect($oauthSignupCalls === 1 && $oauthSignup->status === 200, 'OAUTH_SIGNUP_REACHES_NEXT_HANDLER');

    [$adminSignup, $adminSignupCalls] = post($middleware, $users, new User(true), '/users', [
        'data' => ['attributes' => []],
    ]);
    expect($adminSignupCalls === 1 && $adminSignup->status === 200, 'ADMIN_SIGNUP_REACHES_NEXT_HANDLER');

    [$otherPost, $otherCalls] = post($middleware, $users, new User(false), '/api/discussions', [
        'data' => ['attributes' => ['content' => 'untouched']],
    ]);
    expect($otherCalls === 1 && $otherPost->status === 200, 'UNRELATED_POST_NOT_BLOCKED');

    $middlewareSource = (string) file_get_contents(dirname(__DIR__).'/src/Middleware/RequireFlatRateIdentity.php');
    $extend = (string) file_get_contents(dirname(__DIR__).'/extend.php');
    expect(str_contains($middlewareSource, 'allowsPasswordLogin($user !== null, $user !== null && $user->isAdmin())'), 'MIDDLEWARE_USES_PASSWORD_GATE');
    expect(str_contains($middlewareSource, 'allowsNativeSignup($actor->isAdmin(), $token)'), 'MIDDLEWARE_USES_SIGNUP_GATE');
    expect(substr_count($extend, 'RequireFlatRateIdentity::class') === 2, 'GATE_REGISTERED_ON_FORUM_AND_API');

    if ($failures !== []) {
        fwrite(STDERR, "NATIVE_ADMIN_RECOVERY_GATE=FAIL\n".implode("\n", $failures)."\n");
        exit(1);
    }

    fwrite(STDOUT, "NATIVE_ADMIN_RECOVERY_GATE=PASS\nCHECKS=22\n");
}
