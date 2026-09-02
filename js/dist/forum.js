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

            if (!brand) {
                return;
            }

            items.add('flatrateAffiliatedBrand', m('span.FlatRateAffiliatedBrand', brand), 70);
        });
    });

    module.exports = {};
})();
