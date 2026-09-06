/*! FlatRate Wiki mobile vehicle-brand drawer navigation. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-mobile-brand-drawer', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        // Mount on HeaderSecondary so Search / Notifications / Direct Messages / profile
        // (all HeaderSecondary items) render above Brands in the phone drawer.
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

        function visibleBrandTree() {
            var contract = typeof FlatRateBrandsNavigation !== 'undefined' ? FlatRateBrandsNavigation : null;
            return contract && typeof contract.resolve === 'function' ? contract.resolve(app) : [];
        }

        function currentTagSlug() {
            if (!m.route || typeof m.route.param !== 'function') {
                return '';
            }

            return String(m.route.param('tags') || '').toLowerCase();
        }

        function renderBrandItem(node, activeSlug, child) {
            var slug = String(node.slug);
            var active = slug.toLowerCase() === activeSlug;
            var selector = 'li.FlatRateMobileBrandDrawer-item' + (active ? '.active' : '');
            if (child) {
                selector += '.FlatRateMobileBrandDrawer-item--child';
            }

            return m(
                selector,
                m(
                    TagLinkButton,
                    {
                        model: node.tag,
                        params: {},
                        className: 'FlatRateMobileBrandDrawer-link'
                    },
                    node.name
                )
            );
        }

        function renderBrandNode(node, activeSlug) {
            var children = node.children || [];
            return [
                renderBrandItem(node, activeSlug, false),
                children.length
                    ? m(
                        'li.FlatRateMobileBrandDrawer-children',
                        m(
                            'ul',
                            children.map(function (child) {
                                return renderBrandItem(child, activeSlug, true);
                            })
                        )
                    )
                    : null
            ];
        }

        extend(HeaderSecondary.prototype, 'items', function (items) {
            var tree = visibleBrandTree();
            if (!tree.length) {
                return;
            }

            var activeSlug = currentTagSlug();
            var brandItems = [];
            tree.forEach(function (node) {
                brandItems = brandItems.concat(renderBrandNode(node, activeSlug).filter(Boolean));
            });

            // Below session/profile (priority 0) and Messages (5) / Notifications (10) / Search (30).
            items.add(
                'flatrateMobileBrandDrawer',
                m('nav.FlatRateMobileBrandDrawer', { 'aria-label': 'Brands' }, [
                    m('div.FlatRateMobileBrandDrawer-title', 'Brands'),
                    m(
                        'ul.FlatRateMobileBrandDrawer-links',
                        brandItems
                    )
                ]),
                -50
            );
        });
    });

    module.exports = {};
})();
