<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use FlatRate\SupabaseOAuth\Activity\FlatRateSubjectResolver;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class TestShareCreateController extends AbstractAdminGamifyController
{
    public function __construct(
        AdminGamifyBridgeClient $bridge,
        QualitySignalsService $quality,
        private FlatRateSubjectResolver $subjects
    ) {
        parent::__construct($bridge, $quality);
    }

    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        $json = AdminGamifyHttp::parseJsonBody($request);

        $sender = $this->subjects->resolveSub($actor);
        if ($sender === null) {
            throw new AdminGamifyRequestException('admin_gamify_sender_identity_required', 400);
        }

        $payload = $this->bridgeAction('test_share_create', [
            'actor_flarum_user_id' => (int) $actor->id,
            'test_session_id' => $json['test_session_id'] ?? null,
            'sender_supabase_user_id' => $sender,
            'target_type' => $json['target_type'] ?? null,
            'target_path' => $json['target_path'] ?? null,
            'share_intent' => $json['share_intent'] ?? null,
        ]);

        // Defense-in-depth: never serialize the resolved sender identity.
        unset($payload['sender_supabase_user_id']);

        return array_merge(['ok' => true], $payload);
    }
}
