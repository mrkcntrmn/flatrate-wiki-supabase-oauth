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
        var LinkButton =
            compat['components/LinkButton'] ||
            compat['flarum/common/components/LinkButton'] ||
            compat['flarum/forum/components/LinkButton'];
        var Link =
            compat['components/Link'] ||
            compat['flarum/common/components/Link'];

        HeaderSecondary = HeaderSecondary && (HeaderSecondary.default || HeaderSecondary);
        TagLinkButton = TagLinkButton && (TagLinkButton.default || TagLinkButton);
        LinkButton = LinkButton && (LinkButton.default || LinkButton);
        Link = Link && (Link.default || Link);

        if (typeof extend !== 'function' || typeof m !== 'function' || !HeaderSecondary || !TagLinkButton) {
            return;
        }

        var EXPECTED_MANIFEST_GROUP_IDS = ['community', 'technician-topics', 'brands'];

        function validNavigationManifest(value) {
            if (!value || typeof value !== 'object' || Array.isArray(value)) {
                return false;
            }

            if (value.kind !== 'forum-navigation-runtime-manifest') {
                return false;
            }

            if (value.schemaVersion !== 1) {
                return false;
            }

            if (!Array.isArray(value.groups)) {
                return false;
            }

            var seen = {};
            value.groups.forEach(function (group) {
                if (group && typeof group.id === 'string') {
                    seen[group.id] = true;
                }
            });

            return EXPECTED_MANIFEST_GROUP_IDS.every(function (id) {
                return Boolean(seen[id]);
            });
        }

        function readNavigationManifest(appInstance) {
            if (!appInstance || !appInstance.forum || typeof appInstance.forum.attribute !== 'function') {
                return { present: false, value: undefined };
            }

            var value = appInstance.forum.attribute('flatrateForumNavigationManifest');
            if (value === undefined || value === null) {
                return { present: false, value: value };
            }

            return { present: true, value: value };
        }

        function resolveDrawerDecision(appInstance) {
            var read = readNavigationManifest(appInstance);
            if (!read.present) {
                return { mode: 'legacy' };
            }

            if (!validNavigationManifest(read.value)) {
                return { mode: 'fail-closed' };
            }

            return { mode: 'canonical', manifest: read.value };
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

        function currentRoutePath() {
            if (!m.route || typeof m.route.get !== 'function') {
                return '';
            }

            return String(m.route.get() || '').split('?')[0];
        }

        function routePathIsActive(href) {
            if (!href) {
                return false;
            }

            return currentRoutePath() === String(href).split('?')[0];
        }

        function forumRoute(name, params) {
            if (!app || typeof app.route !== 'function') {
                return null;
            }

            try {
                var href = arguments.length > 1 ? app.route(name, params) : app.route(name);
                return href ? String(href) : null;
            } catch (err) {
                return null;
            }
        }

        function routeLinkComponent() {
            return LinkButton || Link || null;
        }

        function tagsBySlug() {
            var map = {};
            if (!app || !app.store || typeof app.store.all !== 'function') {
                return map;
            }

            var tags = app.store.all('tags');
            if (!Array.isArray(tags)) {
                return map;
            }

            tags.forEach(function (tag) {
                if (tag && typeof tag.slug === 'function') {
                    map[String(tag.slug()).toLowerCase()] = tag;
                }
            });

            return map;
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
            var children = (node.children || []).filter(function (child) {
                return child && child.tag;
            });

            if (!node.tag) {
                return children.reduce(function (acc, child) {
                    return acc.concat(renderBoardNode(child, activeSlug).filter(Boolean));
                }, []);
            }

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

        function renderLegacyNavigation() {
            var groups = visibleGroups();
            if (!groups.length) {
                return null;
            }

            var activeSlug = currentTagSlug();

            return m(
                'nav.FlatRateForumNav.FlatRateForumNav--drawer',
                { 'aria-label': 'Forum navigation', 'data-nav-source': 'legacy-contract' },
                groups.map(function (group) {
                    return renderGroup(group, activeSlug);
                })
            );
        }

        function resolveManifestBoard(board, bySlug) {
            if (!board || !board.slug) {
                return null;
            }

            var tag = bySlug[String(board.slug).toLowerCase()] || null;
            var children = (board.children || [])
                .map(function (child) {
                    return resolveManifestBoard(child, bySlug);
                })
                .filter(Boolean);

            if (!tag && !children.length) {
                return null;
            }

            return {
                slug: board.slug,
                displayName: board.name,
                tag: tag,
                children: children
            };
        }

        function renderDirectLinkGroup(group, href, active) {
            var RouteLink = routeLinkComponent();
            var titleSelector = 'h3.FlatRateForumNav-groupTitle.FlatRateForumNav-groupTitle--direct';
            if (active) {
                titleSelector += '.active';
            }

            return m('section.FlatRateForumNav-group', { 'data-group': group.id, 'data-mode': 'link' }, [
                m(
                    titleSelector,
                    m(
                        RouteLink,
                        {
                            href: href,
                            className: 'FlatRateForumNav-link FlatRateForumNav-directLink'
                        },
                        group.label
                    )
                ),
                m('ul.FlatRateForumNav-links', [])
            ]);
        }

        function renderCanonicalBrandGroup(group, activeSlug) {
            var bySlug = tagsBySlug();
            var boardItems = [];
            (group.boards || []).forEach(function (board) {
                var node = resolveManifestBoard(board, bySlug);
                if (node) {
                    boardItems = boardItems.concat(renderBoardNode(node, activeSlug).filter(Boolean));
                }
            });

            return m('section.FlatRateForumNav-group', { 'data-group': group.id, 'data-mode': 'tree' }, [
                m('h3.FlatRateForumNav-groupTitle', group.label),
                m('ul.FlatRateForumNav-links', boardItems)
            ]);
        }

        function groupById(manifest, id) {
            for (var i = 0; i < manifest.groups.length; i += 1) {
                if (manifest.groups[i] && manifest.groups[i].id === id) {
                    return manifest.groups[i];
                }
            }

            return null;
        }

        function renderCanonicalNavigation(manifest) {
            if (!routeLinkComponent()) {
                return null;
            }

            var community = groupById(manifest, 'community');
            var technician = groupById(manifest, 'technician-topics');
            var brands = groupById(manifest, 'brands');

            if (!community || community.mode !== 'link') {
                return null;
            }

            if (!technician || technician.mode !== 'link') {
                return null;
            }

            if (!brands || brands.mode !== 'tree') {
                return null;
            }

            var communityRouteKey =
                (community.destination && community.destination.routeKey) || 'community';
            var communityHref = forumRoute(communityRouteKey);
            var technicianSlug = technician.destination && technician.destination.slug;
            var technicianHref = technicianSlug ? forumRoute('tag', { tags: technicianSlug }) : null;

            if (!communityHref || !technicianHref) {
                return null;
            }

            var activeSlug = currentTagSlug();
            var technicianActive =
                String(technicianSlug || '').toLowerCase() === activeSlug || routePathIsActive(technicianHref);

            return m(
                'nav.FlatRateForumNav.FlatRateForumNav--drawer',
                { 'aria-label': 'Forum navigation', 'data-nav-source': 'canonical-manifest' },
                [
                    renderDirectLinkGroup(community, communityHref, routePathIsActive(communityHref)),
                    renderDirectLinkGroup(technician, technicianHref, technicianActive),
                    renderCanonicalBrandGroup(brands, activeSlug)
                ]
            );
        }

        extend(HeaderSecondary.prototype, 'items', function (items) {
            var decision = resolveDrawerDecision(app);
            if (decision.mode === 'fail-closed') {
                return;
            }

            var nav =
                decision.mode === 'canonical'
                    ? renderCanonicalNavigation(decision.manifest)
                    : renderLegacyNavigation();

            if (!nav) {
                return;
            }

            // Below session/profile (priority 0) and Messages (5) / Notifications (10) / Search (30).
            items.add('flatrateForumNavigationDrawer', nav, -50);
        });
    });

    module.exports = {};
})();
