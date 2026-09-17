/*! FlatRate GROWTH-001B plain-voting foundation — UI suppression + gate-aware chrome. */
(function () {
  'use strict';

  if (typeof app === 'undefined') {
    return;
  }

  app.initializers.add(
    'flatrate-wiki-plain-voting',
    function () {
      function coreExport(id) {
        if (
          typeof flarum !== 'undefined' &&
          flarum.reg &&
          typeof flarum.reg.get === 'function'
        ) {
          var registered = flarum.reg.get('core', id);
          if (registered) {
            return registered;
          }
        }
        var compat =
          typeof flarum !== 'undefined' && flarum.core && flarum.core.compat
            ? flarum.core.compat
            : null;
        return compat && compat[id] ? compat[id] : null;
      }

      var extendModule = coreExport('common/extend');
      var extend =
        extendModule && typeof extendModule.extend === 'function'
          ? extendModule.extend
          : null;
      var override =
        extendModule && typeof extendModule.override === 'function'
          ? extendModule.override
          : null;

      function votingEnabled() {
        try {
          return !!(app.forum && app.forum.attribute('flatRateVotingEnabled'));
        } catch (e) {
          return false;
        }
      }

      // --- Suppress FoF Gamification product surfaces (V1) ---
      // Prefer ItemList removal; safe no-ops when FoF UI is absent.

      try {
        var UserCard = coreExport('forum/components/UserCard');
        if (UserCard && UserCard.prototype && UserCard.prototype.infoItems && extend) {
          extend(UserCard.prototype, 'infoItems', function (items) {
            if (items && typeof items.remove === 'function') {
              items.remove('points');
            }
          });
        }
      } catch (e) {}

      try {
        var CommentPost = coreExport('forum/components/CommentPost');
        if (CommentPost && CommentPost.prototype && CommentPost.prototype.headerItems && extend) {
          extend(CommentPost.prototype, 'headerItems', function (items) {
            if (items && typeof items.remove === 'function') {
              items.remove('user-card');
              // FoF post rank label key observed in provider UI
              items.remove('rank');
              items.remove('ranks');
            }
          });
        }
      } catch (e) {}

      try {
        var UserPage = coreExport('forum/components/UserPage');
        if (UserPage && UserPage.prototype && UserPage.prototype.navItems && extend) {
          extend(UserPage.prototype, 'navItems', function (items) {
            if (items && typeof items.remove === 'function') {
              items.remove('votes');
              items.remove('upvotes');
            }
          });
        }
      } catch (e) {}

      try {
        var IndexPage = coreExport('forum/components/IndexPage');
        if (IndexPage && IndexPage.prototype && IndexPage.prototype.viewItems && extend) {
          extend(IndexPage.prototype, 'viewItems', function (items) {
            // No rankings nav for ordinary product
            if (items && typeof items.remove === 'function') {
              items.remove('rankings');
            }
          });
        }
      } catch (e) {}

      try {
        // DiscussionList sort map: remove FoF hot/votes when present
        var DiscussionListState = coreExport('forum/states/DiscussionListState');
        if (DiscussionListState && DiscussionListState.prototype && DiscussionListState.prototype.sortMap && override) {
          override(DiscussionListState.prototype, 'sortMap', function (original) {
            var map = original ? original.call(this) : {};
            if (map && typeof map === 'object') {
              delete map.hot;
              delete map.votes;
            }
            return map;
          });
        }
      } catch (e) {}

      // Hide FoF vote controls while FlatRate gate is closed.
      // When gate opens, provider controls remain (plain ▲/▼ aggregate).
      try {
        var Post = coreExport('forum/components/Post');
        if (Post && Post.prototype && Post.prototype.actionItems && extend) {
          extend(Post.prototype, 'actionItems', function (items) {
            if (votingEnabled()) {
              return;
            }
            if (items && typeof items.remove === 'function') {
              items.remove('votes');
              items.remove('upvote');
              items.remove('downvote');
              items.remove('vote');
            }
          });
        }
      } catch (e) {}
    },
    // Run after FoF gamification initializers when both are present.
    100
  );
})();
