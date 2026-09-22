<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use FlatRate\SupabaseOAuth\Activity\HmacSigner;
use Flarum\Foundation\AbstractServiceProvider;

final class AdminGamifyServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(AdminGamifyBridgeConfig::class);
        $this->container->singleton(AdminGamifyBridgeClient::class);
        $this->container->singleton(QualitySignalsService::class);
        $this->container->singleton(OverviewController::class);
        $this->container->singleton(QualityController::class);
        $this->container->singleton(SharingController::class);
        $this->container->singleton(ReferralsController::class);
        $this->container->singleton(TestSessionStartController::class);
        $this->container->singleton(TestSessionEndController::class);
        $this->container->singleton(TestSessionStatusController::class);
        $this->container->singleton(TestShareCreateController::class);
        $this->container->singleton(TestShareStatusController::class);
        $this->container->singleton(TestLaunchCreateController::class);

        // Reuse the existing HMAC signer singleton when bound.
        if (! $this->container->bound(HmacSigner::class)) {
            $this->container->singleton(HmacSigner::class);
        }
    }
}
