/*! FlatRate Wiki forum authentication and reply metadata bundle. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-direct-login', function () {
        var productLoginUrl = 'https://flatrate.wiki/login';
        var redirectStarted = false;

        function currentForumReturnTo() {
            var value = window.location.pathname + window.location.search + window.location.hash;
            return value.indexOf('/') === 0 && value.indexOf('//') !== 0 ? value : '/';
        }

        function loginDestination() {
            var next = '/community?returnTo=' + encodeURIComponent(currentForumReturnTo());
            var destination = new URL(productLoginUrl);
            destination.searchParams.set('next', next);
            return destination.toString();
        }

        function redirectLoginModal() {
            if (redirectStarted || !document.querySelector('.LogInModal')) {
                return false;
            }

            redirectStarted = true;
            window.location.assign(loginDestination());
            return true;
        }

        if (redirectLoginModal()) {
            return;
        }

        var root = document.documentElement;
        if (!root || typeof MutationObserver === 'undefined') {
            return;
        }

        var observer = new MutationObserver(function () {
            if (redirectLoginModal()) {
                observer.disconnect();
            }
        });

        observer.observe(root, { childList: true, subtree: true });
    });

    app.initializers.add('flatrate-wiki-reply-job-breakdown', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var ReplyComposer = compat['components/ReplyComposer'] || compat['flarum/forum/components/ReplyComposer'];
        var EditPostComposer = compat['components/EditPostComposer'] || compat['flarum/forum/components/EditPostComposer'];
        var CommentPost = compat['components/CommentPost'] || compat['flarum/forum/components/CommentPost'];
        var tagLabelModule =
            compat['tags/helpers/tagLabel'] ||
            compat['tags/common/helpers/tagLabel'] ||
            compat['flarum/tags/common/helpers/tagLabel'];
        var tagLabel = tagLabelModule && (tagLabelModule.default || tagLabelModule);
        var jobBreakdownTagSlug = 'job-breakdown';

        ReplyComposer = ReplyComposer && (ReplyComposer.default || ReplyComposer);
        EditPostComposer = EditPostComposer && (EditPostComposer.default || EditPostComposer);
        CommentPost = CommentPost && (CommentPost.default || CommentPost);

        if (typeof extend !== 'function' || typeof m !== 'function') {
            return;
        }

        function markerEnabled(post) {
            return !!(post && typeof post.attribute === 'function' && post.attribute('flatRateJobBreakdown'));
        }

        function postNumber(post) {
            if (!post || typeof post.number !== 'function') {
                return null;
            }

            return Number(post.number());
        }

        function isDiscussionStarter(post) {
            return postNumber(post) === 1;
        }

        function resolveJobBreakdownTag() {
            if (!app || !app.store || typeof app.store.all !== 'function') {
                return null;
            }

            var tags = app.store.all('tags');
            if (!tags || typeof tags.filter !== 'function') {
                return null;
            }

            return (
                tags.filter(function (tag) {
                    return tag && typeof tag.slug === 'function' && tag.slug() === jobBreakdownTagSlug;
                })[0] || null
            );
        }

        function renderJobBreakdownTagLabel() {
            if (typeof tagLabel !== 'function') {
                return null;
            }

            var tag = resolveJobBreakdownTag();
            if (!tag) {
                return null;
            }

            return tagLabel(tag);
        }

        function markerControl(component) {
            return m('label.FlatRateReplyJobBreakdownToggle', [
                m('input', {
                    type: 'checkbox',
                    checked: !!component.flatRateJobBreakdown,
                    onchange: function (event) {
                        component.flatRateJobBreakdown = !!event.target.checked;
                    }
                }),
                m('span', 'Job Breakdown')
            ]);
        }

        function applyMarkerAttribute(component, data) {
            data.flatRateJobBreakdown = !!component.flatRateJobBreakdown;
        }

        if (ReplyComposer) {
            extend(ReplyComposer.prototype, 'oninit', function () {
                this.flatRateJobBreakdown = false;
            });

            extend(ReplyComposer.prototype, 'headerItems', function (items) {
                items.add('flatrateJobBreakdown', markerControl(this), -5);
            });

            extend(ReplyComposer.prototype, 'data', function (data) {
                applyMarkerAttribute(this, data);
            });
        }

        if (EditPostComposer) {
            extend(EditPostComposer.prototype, 'oninit', function () {
                this.flatRateJobBreakdown = markerEnabled(this.attrs && this.attrs.post);
            });

            extend(EditPostComposer.prototype, 'headerItems', function (items) {
                if (!isDiscussionStarter(this.attrs && this.attrs.post)) {
                    items.add('flatrateJobBreakdown', markerControl(this), -5);
                }
            });

            extend(EditPostComposer.prototype, 'data', function (data) {
                if (!isDiscussionStarter(this.attrs && this.attrs.post)) {
                    applyMarkerAttribute(this, data);
                }
            });
        }

        if (CommentPost) {
            extend(CommentPost.prototype, 'headerItems', function (items) {
                if (!markerEnabled(this.attrs && this.attrs.post)) {
                    return;
                }

                var label = renderJobBreakdownTagLabel();
                if (label) {
                    items.add('flatrateJobBreakdownTag', label, -5);
                }
            });
        }
    });

    app.initializers.add('flatrate-wiki-affiliated-brand', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var PostUser = compat['components/PostUser'] || compat['flarum/forum/components/PostUser'];
        var affiliatedBrandFieldName = 'Affiliated Brand';

        PostUser = PostUser && (PostUser.default || PostUser);

        if (typeof extend !== 'function' || typeof m !== 'function' || !PostUser) {
            return;
        }

        function resolveAffiliatedBrandField() {
            if (!app || !app.store || typeof app.store.all !== 'function') {
                return null;
            }

            var fields = app.store.all('masquerade-field');
            if (!fields || typeof fields.filter !== 'function') {
                return null;
            }

            var matches = fields.filter(function (field) {
                if (!field || typeof field.attribute !== 'function') {
                    return false;
                }

                return (
                    field.attribute('name') === affiliatedBrandFieldName &&
                    field.attribute('type') === 'select' &&
                    !field.attribute('deleted_at')
                );
            });

            return matches.length === 1 ? matches[0] : null;
        }

        function affiliatedBrandForUser(user) {
            var field = resolveAffiliatedBrandField();

            if (!field || !user || typeof user.masqueradeAnswers !== 'function') {
                return null;
            }

            var answers = user.masqueradeAnswers() || [];
            var fieldId = String(field.id());
            var answer = answers.filter(function (candidate) {
                return (
                    candidate &&
                    typeof candidate.attribute === 'function' &&
                    String(candidate.attribute('fieldId')) === fieldId
                );
            })[0];

            if (!answer) {
                return null;
            }

            var value = String(answer.attribute('content') || '').trim();

            return value || null;
        }

        extend(PostUser.prototype, 'linkChildren', function (items, user) {
            var brand = affiliatedBrandForUser(user);

            if (!brand || !items.has('username')) {
                return;
            }

            var usernameVnode = items.get('username');

            items.add(
                'username',
                m('span.FlatRatePostUserIdentityStack', [
                    usernameVnode,
                    m('span.FlatRateAffiliatedBrand', brand)
                ]),
                items.getPriority('username')
            );
        });
    });

    module.exports = {};
})();

/*! FlatRate Wiki desktop IndexPage/DiscussionPage sidebar forum navigation. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-forum-navigation-sidebar', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var IndexPage = compat['components/IndexPage'] || compat['flarum/forum/components/IndexPage'];
        var DiscussionPage = compat['components/DiscussionPage'] || compat['flarum/forum/components/DiscussionPage'];
        var LinkButton = compat['components/LinkButton'] || compat['flarum/common/components/LinkButton'];

        IndexPage = IndexPage && (IndexPage.default || IndexPage);
        DiscussionPage = DiscussionPage && (DiscussionPage.default || DiscussionPage);
        LinkButton = LinkButton && (LinkButton.default || LinkButton);

        if (typeof extend !== 'function' || typeof m !== 'function' || !LinkButton) {
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
            return Boolean(activeSlug) && String(node.slug).toLowerCase() === activeSlug;
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

        function groupedNavigationVnode(modifier, activeSlug) {
            var groups = visibleGroups();
            if (!groups.length) {
                return null;
            }

            var selector = 'nav.FlatRateForumNav.FlatRateForumNav--sidebar';
            if (modifier) {
                selector += '.' + modifier;
            }

            return m(
                selector,
                { 'aria-label': 'Forum navigation' },
                groups.map(function (group) {
                    return renderGroup(group, activeSlug);
                })
            );
        }

        function addGroupedNavigation(items, modifier, priority, activeSlug) {
            if (!items || typeof items.add !== 'function') {
                return;
            }
            if (typeof items.has === 'function' && items.has('flatrateForumNavigation')) {
                return;
            }

            var vnode = groupedNavigationVnode(modifier, activeSlug);
            if (!vnode) {
                return;
            }

            items.add('flatrateForumNavigation', vnode, priority);
        }

        if (IndexPage) {
            extend(IndexPage.prototype, 'sidebarItems', function (items) {
                addGroupedNavigation(items, 'FlatRateForumNav--index', -20, currentTagSlug());
            });
        }

        if (DiscussionPage) {
            // Flarum 1.8.19 DiscussionPage.sidebarItems: controls=100, scrubber=-100.
            // Keep FlatRate grouped nav after both native items.
            extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
                addGroupedNavigation(items, 'FlatRateForumNav--discussion', -200, '');
            });
        }
    });
})();
