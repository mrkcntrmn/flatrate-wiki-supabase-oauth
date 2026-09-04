/*! FlatRate Wiki mobile vehicle-brand drawer navigation. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-mobile-brand-drawer', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        // Mount on HeaderSecondary so Search / Notifications / Direct Messages / profile
        // (all HeaderSecondary items) render above Vehicle Brands in the phone drawer.
        // Flarum's drawer mounts primary header controls before secondary ones in the DOM.
        var HeaderSecondary =
            compat['components/HeaderSecondary'] || compat['flarum/forum/components/HeaderSecondary'];
        var TagLinkButton =
            compat['tags/components/TagLinkButton'] ||
            compat['flarum/tags/forum/components/TagLinkButton'];

        HeaderSecondary = HeaderSecondary && (HeaderSecondary.default || HeaderSecondary);
        TagLinkButton = TagLinkButton && (TagLinkButton.default || TagLinkButton);

        if (typeof extend !== 'function' || typeof m !== 'function' || !HeaderSecondary || !TagLinkButton) {
            return;
        }

        // Community boards that are primary roots but not vehicle makes.
        var EXCLUDED_BRAND_SLUGS = {
            'start-here': true,
            'general-shop-discussion': true
        };

        function tagPosition(tag) {
            if (!tag || typeof tag.position !== 'function') {
                return null;
            }

            var value = tag.position();
            return value === null || typeof value === 'undefined' ? null : Number(value);
        }

        function isPrimaryRootTag(tag) {
            if (!tag || typeof tag.slug !== 'function' || typeof tag.name !== 'function') {
                return false;
            }

            if (tagPosition(tag) === null) {
                return false;
            }

            var slug = String(tag.slug()).toLowerCase();
            if (EXCLUDED_BRAND_SLUGS[slug]) {
                return false;
            }

            if (typeof tag.isChild === 'function') {
                return !tag.isChild();
            }

            return !(typeof tag.parent === 'function' && tag.parent());
        }

        function visibleBrandTags() {
            if (!app || !app.store || typeof app.store.all !== 'function') {
                return [];
            }

            var tags = app.store.all('tags');
            if (!Array.isArray(tags)) {
                return [];
            }

            return tags.filter(isPrimaryRootTag).sort(function (left, right) {
                var positionDelta = tagPosition(left) - tagPosition(right);
                if (positionDelta !== 0) {
                    return positionDelta;
                }

                return String(left.name()).localeCompare(String(right.name()));
            });
        }

        function currentTagSlug() {
            if (!m.route || typeof m.route.param !== 'function') {
                return '';
            }

            return String(m.route.param('tags') || '').toLowerCase();
        }

        function renderBrandItem(tag, activeSlug) {
            var slug = String(tag.slug());
            var active = slug.toLowerCase() === activeSlug;
            var selector = 'li.FlatRateMobileBrandDrawer-item' + (active ? '.active' : '');

            return m(
                selector,
                m(
                    TagLinkButton,
                    {
                        model: tag,
                        params: {},
                        className: 'FlatRateMobileBrandDrawer-link'
                    },
                    tag.name()
                )
            );
        }

        extend(HeaderSecondary.prototype, 'items', function (items) {
            var tags = visibleBrandTags();
            if (!tags.length) {
                return;
            }

            var activeSlug = currentTagSlug();

            // Below session/profile (priority 0) and Messages (5) / Notifications (10) / Search (30).
            items.add(
                'flatrateMobileBrandDrawer',
                m('nav.FlatRateMobileBrandDrawer', { 'aria-label': 'Vehicle brands' }, [
                    m('div.FlatRateMobileBrandDrawer-title', 'Vehicle Brands'),
                    m(
                        'ul.FlatRateMobileBrandDrawer-links',
                        tags.map(function (tag) {
                            return renderBrandItem(tag, activeSlug);
                        })
                    )
                ]),
                -50
            );
        });
    });

    module.exports = {};
})();
