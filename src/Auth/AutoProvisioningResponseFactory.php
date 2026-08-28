<?php

namespace FlatRate\SupabaseOAuth\Auth;

use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\Rememberer;
use Flarum\User\LoginProvider;
use FoF\OAuth\Errors\AuthenticationException;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class AutoProvisioningResponseFactory extends ResponseFactory
{
    private bool $flatRateFlow = false;

    public function __construct(
        Rememberer $rememberer,
        private FlatRateUserProvisioner $provisioner
    ) {
        parent::__construct($rememberer);
    }

    public function make(string $provider, string $identifier, callable $configureRegistration): ResponseInterface
    {
        if ($provider !== 'flatrate') {
            return parent::make($provider, $identifier, $configureRegistration);
        }

        // Keep the browser OAuth route as a rollback/fallback path while normal
        // FlatRate.wiki product navigation moves to the one-time ticket bridge.
        $this->flatRateFlow = true;

        try {
            if ($user = LoginProvider::logIn($provider, $identifier)) {
                return $this->makeLoggedInResponse($user);
            }

            $configureRegistration($registration = new Registration());
            $payload = $registration->getPayload();
            $payload = is_array($payload) ? $payload : [];

            $sub = trim((string) ($payload['sub'] ?? ''));
            if ($sub === '' || ! hash_equals($identifier, $sub)) {
                throw new AuthenticationException('invalid_subject');
            }

            $email = trim((string) ($payload['email'] ?? ''));
            $verified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $user = $this->provisioner->ensure($sub, $email, $verified, $payload);

            return $this->makeLoggedInResponse($user);
        } finally {
            $this->flatRateFlow = false;
        }
    }

    protected function makeResponse(array $payload): ResponseInterface
    {
        if (! $this->flatRateFlow) {
            return parent::makeResponse($payload);
        }

        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ($json === false) {
            throw new RuntimeException('Unable to encode FlatRate OAuth response.');
        }

        // Stock Flarum 1.8 assumes OAuth runs in a same-origin popup. Preserve
        // that behavior when an opener exists; otherwise finish the fallback
        // top-level flow at Community Settings instead of a blank callback page.
        $template = <<<'HTML'
<script>(function(){var payload=%s;try{if(window.opener&&window.opener.app&&typeof window.opener.app.authenticationComplete==='function'){window.opener.app.authenticationComplete(payload);window.close();return;}}catch(error){}window.location.replace('/settings');})();</script>
HTML;

        return new HtmlResponse(sprintf($template, $json));
    }
}
