/*! FlatRate Wiki desktop/index sidebar forum navigation. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-forum-navigation-sidebar', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var IndexPage = compat['components/IndexPage'] || compat['flarum/forum/components/IndexPage'];
        var LinkButton = compat['components/LinkButton'] || compat['flarum/common/components/LinkButton'];

        IndexPage = IndexPage && (IndexPage.default || IndexPage);
        LinkButton = LinkButton && (LinkButton.default || LinkButton);

        if (typeof extend !== 'function' || typeof m !== 'function' || !IndexPage || !LinkButton) {
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

        function renderBoardLink(node, activeSlug, child) {
            var active = nodeIsActive(node, activeSlug);
            var branchActive = !active && nodeHasActiveDescendant(node, activeSlug);
            var className = 'FlatRateForumNav-link';
            if (child) {
                className += ' FlatRateForumNav-link--child';
            }
            if (branchActive) {
                className += ' FlatRateForumNav-link--branch-active';
            }

            return m(
                LinkButton,
                {
                    href: app.route('tag', { tags: String(node.slug) }),
                    className: className,
                    active: active
                },
                node.displayName
            );
        }

        function renderBoardNode(node, activeSlug) {
            var children = node.children || [];
            return m('li.FlatRateForumNav-item', [
                renderBoardLink(node, activeSlug, false),
                children.length
                    ? m(
                        'ul.FlatRateForumNav-children',
                        children.map(function (child) {
                            return m('li.FlatRateForumNav-item.FlatRateForumNav-item--child', [
                                renderBoardLink(child, activeSlug, true)
                            ]);
                        })
                    )
                    : null
            ]);
        }

        function renderGroup(group, activeSlug) {
            return m('section.FlatRateForumNav-group', { 'data-group': group.id }, [
                m('h3.FlatRateForumNav-groupTitle', group.label),
                m(
                    'ul.FlatRateForumNav-links',
                    group.children.map(function (node) {
                        return renderBoardNode(node, activeSlug);
                    })
                )
            ]);
        }

        extend(IndexPage.prototype, 'sidebarItems', function (items) {
            var groups = visibleGroups();
            if (!groups.length) {
                return;
            }

            var activeSlug = currentTagSlug();

            items.add(
                'flatrateForumNavigation',
                m(
                    'nav.FlatRateForumNav.FlatRateForumNav--sidebar',
                    { 'aria-label': 'Forum navigation' },
                    groups.map(function (group) {
                        return renderGroup(group, activeSlug);
                    })
                ),
                -20
            );
        });
    });
})();

module.exports = {};
