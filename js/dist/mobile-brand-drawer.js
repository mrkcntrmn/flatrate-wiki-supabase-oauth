/*! FlatRate Wiki mobile forum navigation drawer. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-mobile-forum-navigation', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        // Mount on HeaderSecondary so Search / Notifications / Direct Messages / profile
        // (all HeaderSecondary items) render above forum navigation in the phone drawer.
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

        function visibleGroups() {
            var contract = typeof FlatRateForumNavigation !== 'undefined' ? FlatRateForumNavigation : null;
            return contract && typeof contract.resolve === 'function' ? contract.resolve(app) : [];
        }

        function currentTagSlug() {
            if (!m.route || typeof m.route.param !== 'function') {
                return '';
            }

            return String(m.route.param('tags') || '').toLowerCase();
        }

        function nodeIsActive(node, activeSlug) {
            return String(node.slug).toLowerCase() === activeSlug;
        }

        function nodeHasActiveDescendant(node, activeSlug) {
            return (node.children || []).some(function (child) {
                return nodeIsActive(child, activeSlug) || nodeHasActiveDescendant(child, activeSlug);
            });
        }

        function renderBoardItem(node, activeSlug, child) {
            var active = nodeIsActive(node, activeSlug);
            var branchActive = !active && nodeHasActiveDescendant(node, activeSlug);
            var selector = 'li.FlatRateForumNav-item';
            if (active) {
                selector += '.active';
            }
            if (branchActive) {
                selector += '.FlatRateForumNav-item--branch-active';
            }
            if (child) {
                selector += '.FlatRateForumNav-item--child';
            }

            return m(
                selector,
                m(
                    TagLinkButton,
                    {
                        model: node.tag,
                        params: {},
                        className: 'FlatRateForumNav-link'
                    },
                    node.displayName
                )
            );
        }

        function renderBoardNode(node, activeSlug) {
            var children = node.children || [];
            return [
                renderBoardItem(node, activeSlug, false),
                children.length
                    ? m(
                        'li.FlatRateForumNav-children',
                        m(
                            'ul',
                            children.map(function (child) {
                                return renderBoardItem(child, activeSlug, true);
                            })
                        )
                    )
                    : null
            ];
        }

        function renderGroup(group, activeSlug) {
            var boardItems = [];
            group.children.forEach(function (node) {
                boardItems = boardItems.concat(renderBoardNode(node, activeSlug).filter(Boolean));
            });

            return m('section.FlatRateForumNav-group', { 'data-group': group.id }, [
                m('h3.FlatRateForumNav-groupTitle', group.label),
                m('ul.FlatRateForumNav-links', boardItems)
            ]);
        }

        extend(HeaderSecondary.prototype, 'items', function (items) {
            var groups = visibleGroups();
            if (!groups.length) {
                return;
            }

            var activeSlug = currentTagSlug();

            // Below session/profile (priority 0) and Messages (5) / Notifications (10) / Search (30).
            items.add(
                'flatrateForumNavigationDrawer',
                m(
                    'nav.FlatRateForumNav.FlatRateForumNav--drawer',
                    { 'aria-label': 'Forum navigation' },
                    groups.map(function (group) {
                        return renderGroup(group, activeSlug);
                    })
                ),
                -50
            );
        });
    });

    module.exports = {};
})();
