<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Console\AbstractCommand;

final class DrainActivityOutboxCommand extends AbstractCommand
{
    public function __construct(
        private ActivityOutboxDrainer $drainer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('flatrate:activity:drain-outbox')
            ->setDescription('Drain due FlatRate activity outbox rows with fresh HMAC delivery');
    }

    protected function fire(): void
    {
        $count = $this->drainer->drain();
        $this->info('flatrate_activity_outbox_drained='.$count);
    }
}
