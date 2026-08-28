<?php

namespace FlatRate\SupabaseOAuth\Auth;

use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\Rememberer;
use Flarum\User\Command\RegisterUser;
use Flarum\User\Command\RegisterUserHandler;
use Flarum\User\Guest;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use FoF\OAuth\Errors\AuthenticationException;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class AutoProvisioningResponseFactory extends ResponseFactory
{
    private bool $flatRateFlow = false;

    public function __construct(
        Rememberer $rememberer,
        private RegisterUserHandler $registerUser
    ) {
        parent::__construct($rememberer);
    }

    public function make(string $provider, string $identifier, callable $configureRegistration): ResponseInterface
    {
        if ($provider !== 'flatrate') {
            return parent::make($provider, $identifier, $configureRegistration);
        }

        // Flarum 1.8's auth response is designed for a same-origin popup. The
        // FlatRate.wiki account page intentionally starts this provider as a
        // top-level navigation so the forum can establish its own cookie. Keep
        // track of that provider here so makeResponse can support both modes
        // without changing the behavior of any other OAuth provider.
        $this->flatRateFlow = true;

        try {
            // Existing links retain Flarum's normal login behavior, including
            // updating the provider's last_login_at timestamp.
            if ($user = LoginProvider::logIn($provider, $identifier)) {
                return $this->makeLoggedInResponse($user);
            }

            $configureRegistration($registration = new Registration());

            $payload = $registration->getPayload();
            $payload = is_array($payload) ? $payload : [];

            $sub = trim((string) ($payload['sub'] ?? ''));
            $email = trim((string) ($payload['email'] ?? ''));
            $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($sub === '' || !hash_equals($identifier, $sub)) {
                throw new AuthenticationException('invalid_subject');
            }

            // The registration token treats its email attribute as trusted and
            // activates that email. Never enter the silent path unless Supabase
            // UserInfo explicitly says the exact address is verified.
            if ($email === '' || !$emailVerified) {
                throw new AuthenticationException('verified_email_required');
            }

            // Email is deliberately NOT an account-linking key. An existing local
            // address means this identity must be linked while authenticated as
            // that Flarum user (FoF OAuth linkTo flow), not silently joined here.
            if (User::where('email', $email)->exists()) {
                throw new AuthenticationException('existing_account_requires_explicit_link');
            }

            $username = NeutralIdentity::handle($sub);
            $nickname = NeutralIdentity::nickname($sub);

            $connection = (new User())->getConnection();

            /** @var User $user */
            $user = $connection->transaction(function () use ($provider, $identifier, $payload, $username, $email, $nickname) {
                $token = RegistrationToken::generate(
                    $provider,
                    $identifier,
                    [
                        'username' => $username,
                        'email' => $email,
                        'nickname' => $nickname,
                    ],
                    $payload
                );
                $token->save();

                // Use Flarum's own registration handler rather than manually
                // inserting rows. This preserves core validation/events, the
                // RegisteringFromProvider hook, nickname persistence, activation,
                // and LoginProvider creation keyed to the immutable OAuth sub.
                return $this->registerUser->handle(new RegisterUser(
                    new Guest(),
                    [
                        'attributes' => [
                            'username' => $username,
                            'email' => $email,
                            'token' => $token->token,
                        ],
                    ]
                ));
            });

            return $this->makeLoggedInResponse($user);
        } finally {
            $this->flatRateFlow = false;
        }
    }

    protected function makeResponse(array $payload): ResponseInterface
    {
        if (!$this->flatRateFlow) {
            return parent::makeResponse($payload);
        }

        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ($json === false) {
            throw new RuntimeException('Unable to encode FlatRate OAuth response.');
        }

        // Stock Flarum 1.8 assumes OAuth always runs in a popup and emits only
        // `window.opener.app.authenticationComplete(...)`. When /auth/flatrate
        // is opened in the top-level tab there is no opener, so that stock
        // response leaves the user on a blank callback page even though the
        // remember-me cookie was successfully issued.
        //
        // Preserve the normal same-origin popup contract for forum-initiated
        // sign-in. If there is no usable opener (including a cross-origin
        // opener), continue in this tab to Community Settings instead.
        $content = sprintf(
            '<script>(function(){var payload=%s;try{if(window.opener&&window.opener.app&&typeof window.opener.app.authenticationComplete==="function"){window.opener.app.authenticationComplete(payload);window.close();return;}}catch(error){}window.location.replace("/settings");})();</script>',
            $json
        );

        // Strip the PHP source-only escaping for double quotes. In a single-
        // quoted PHP string, escaped double quotes would otherwise be emitted
        // literally into JavaScript and cause a syntax error.
        $content = str_replace('\\"', '"', $content);

        return new HtmlResponse($content);
    }
}
