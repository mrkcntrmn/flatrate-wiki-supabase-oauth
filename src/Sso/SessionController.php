<?php

namespace FlatRate\SupabaseOAuth\Sso;

use Flarum\Http\RememberAccessToken;
use Flarum\Http\Rememberer;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionController implements RequestHandlerInterface
{
    public function __construct(
        private TicketStore $tickets,
        private Rememberer $rememberer
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ticket = trim((string) ($request->getQueryParams()['ticket'] ?? ''));
        $entry = $this->tickets->consume($ticket);

        if (! $entry) {
            return new TextResponse(
                'This Community sign-in link is invalid or expired.',
                401,
                [
                    'Cache-Control' => 'no-store',
                    'Referrer-Policy' => 'no-referrer',
                ]
            );
        }

        $token = RememberAccessToken::generate($entry['user']->id);
        $response = new RedirectResponse($entry['returnTo'], 302, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);

        return $this->rememberer->remember($response, $token);
    }
}
