<?php

namespace FlatRate\SupabaseOAuth\Markers;

use Flarum\Post\Event\Deleted;

final class DeletePostMarkers
{
    public function __construct(private PostMarkerStore $markers)
    {
    }

    public function handle(Deleted $event): void
    {
        $this->markers->deleteForPost((int) $event->post->id);
    }
}
