<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Voting\BrandVoteSummary;
use Flarum\Api\Serializer\AbstractSerializer;

/**
 * Forum-bootstrap exact-Brand vote totals keyed by canonical Brand slug.
 *
 * Omits the attribute when the voting provider/read model is unavailable.
 */
final class SerializeBrandVoteSummary
{
    public function __construct(private BrandVoteSummary $summary)
    {
    }

    public function __invoke(AbstractSerializer $serializer, $model, array $attributes): array
    {
        $totals = $this->summary->forActor($serializer->getActor());

        if ($totals !== null) {
            $attributes['flatRateBrandUpvotes'] = $totals;
        }

        return $attributes;
    }
}
