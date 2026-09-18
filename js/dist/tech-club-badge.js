/*! FlatRate Wiki TECH CLUB premium post badge. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-tech-club-badge', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var CommentPost = compat['components/CommentPost'] || compat['flarum/forum/components/CommentPost'];
        var techClubGroupName = 'TECH CLUB';

        CommentPost = CommentPost && (CommentPost.default || CommentPost);

        if (typeof extend !== 'function' || typeof m !== 'function' || !CommentPost) {
            return;
        }

        function groupName(group) {
            if (!group) {
                return '';
            }

            if (typeof group.nameSingular === 'function') {
                return String(group.nameSingular() || '').trim();
            }

            if (typeof group.attribute === 'function') {
                return String(group.attribute('nameSingular') || group.attribute('name_singular') || '').trim();
            }

            return '';
        }

        function isTechClubMember(user) {
            if (!user || typeof user.groups !== 'function') {
                return false;
            }

            var groups = user.groups() || [];
            if (!Array.isArray(groups)) {
                return false;
            }

            return groups.some(function (group) {
                return groupName(group).toUpperCase() === techClubGroupName;
            });
        }

        function authorForPost(post) {
            if (!post || typeof post.user !== 'function') {
                return null;
            }

            return post.user();
        }

        function renderBadge() {
            return m(
                'span.FlatRateTechClubBadge',
                {
                    title: 'TECH CLUB premium member',
                    'aria-label': 'TECH CLUB premium member'
                },
                'TECH CLUB 🧼'
            );
        }

        extend(CommentPost.prototype, 'headerItems', function (items) {
            var author = authorForPost(this.attrs && this.attrs.post);
            if (!isTechClubMember(author)) {
                return;
            }

            items.add('flatrateTechClubBadge', renderBadge(), -6);
        });
    });

    module.exports = {};
})();
