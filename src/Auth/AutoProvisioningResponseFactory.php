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
use Psr\Http\Message\ResponseInterface;

final class AutoProvisioningResponseFactory extends ResponseFactory
{
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
    }
}
