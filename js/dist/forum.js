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
        var extendModule = compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var ReplyComposer = compat['flarum/forum/components/ReplyComposer'];
        var EditPostComposer = compat['flarum/forum/components/EditPostComposer'];
        var CommentPost = compat['flarum/forum/components/CommentPost'];

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
            data.attributes = data.attributes || {};
            data.attributes.flatRateJobBreakdown = !!component.flatRateJobBreakdown;
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
                if (markerEnabled(this.attrs && this.attrs.post)) {
                    items.add('flatrateJobBreakdownBadge', m('span.FlatRateReplyJobBreakdownBadge', 'Job Breakdown'), 85);
                }
            });
        }
    });

    module.exports = {};
})();
