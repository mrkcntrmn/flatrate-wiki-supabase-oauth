<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use FoF\Seo\Event\PreparingPageMeta;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** FoF SEO 4 reads raw username/avatar fields, outside the display-name driver. */
final class ProjectGuestProfileSeo
{
    public function __construct(
        private GuestIdentityProjection $projection,
        private SlugManager $slugs
    ) {
    }

    public function __invoke(PreparingPageMeta $event): void
    {
        if (! RequestUtil::getActor($event->request)->isGuest() || $event->request->getAttribute('routeName') !== 'user') {
            return;
        }

        $slug = $event->request->getQueryParams()['username'] ?? null;
        if (! is_string($slug)) {
            return;
        }

        try {
            $user = $this->slugs->forResource(User::class)->fromSlug($slug, RequestUtil::getActor($event->request));
        } catch (ModelNotFoundException) {
            return;
        }

        $alias = $this->projection->publicAlias($user);
        $properties = $event->properties;
        $properties->setMetaPropertyTag('profile:username', $alias)
            ->setMetaPropertyTag('og:image', '')
            ->setMetaTag('twitter:image', '')
            ->setSchemaJson('image', null)
            ->setSchemaJson('about', null)
            ->setSchemaJson('mainEntity', [
                '@type' => 'Person',
                'name' => $alias,
                'alternateName' => $alias,
                'identifier' => $user->id,
                'agentInteractionStatistic' => [
                    ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/WriteAction', 'userInteractionCount' => (int) $user->comment_count],
                    ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CreateAction', 'userInteractionCount' => (int) $user->discussion_count],
                ],
                'url' => $properties->withApplicationPath('/u/'.$this->projection->publicSlug($user)),
            ]);
    }
}
