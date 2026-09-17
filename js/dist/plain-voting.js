/*! FlatRate GROWTH-001B plain-voting foundation — UI suppression + gate-aware chrome. */
(function (root) {
  'use strict';

  if (typeof app === 'undefined') {
    if (typeof module !== 'undefined') {
      module.exports = {};
    }
    return;
  }

  app.initializers.add('flatrate-wiki-plain-voting', function () {
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

    function unwrap(mod) {
      return mod && (mod.default || mod);
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

    if (typeof extend !== 'function') {
      return;
    }

    function votingEnabled() {
      try {
        return !!(app.forum && app.forum.attribute('flatRateVotingEnabled'));
      } catch (e) {
        return false;
      }
    }

    // Suppress FoF Gamification product surfaces (V1). Safe no-ops when absent.
    try {
      var UserCard = unwrap(coreExport('forum/components/UserCard'));
      if (UserCard && UserCard.prototype && UserCard.prototype.infoItems) {
        extend(UserCard.prototype, 'infoItems', function (items) {
          if (items && typeof items.remove === 'function') {
            items.remove('points');
          }
        });
      }
    } catch (e) {}

    try {
      var CommentPost = unwrap(coreExport('forum/components/CommentPost'));
      if (CommentPost && CommentPost.prototype && CommentPost.prototype.headerItems) {
        extend(CommentPost.prototype, 'headerItems', function (items) {
          if (items && typeof items.remove === 'function') {
            // FoF post rank keys only — never remove core user-card.
            items.remove('rank');
            items.remove('ranks');
          }
        });
      }
    } catch (e) {}

    try {
      var UserPage = unwrap(coreExport('forum/components/UserPage'));
      if (UserPage && UserPage.prototype && UserPage.prototype.navItems) {
        extend(UserPage.prototype, 'navItems', function (items) {
          if (items && typeof items.remove === 'function') {
            items.remove('votes');
            items.remove('upvotes');
          }
        });
      }
    } catch (e) {}

    try {
      var IndexPage = unwrap(coreExport('forum/components/IndexPage'));
      if (IndexPage && IndexPage.prototype && IndexPage.prototype.navItems) {
        extend(IndexPage.prototype, 'navItems', function (items) {
          if (items && typeof items.remove === 'function') {
            items.remove('rankings');
          }
        });
      }
    } catch (e) {}

    try {
      var DiscussionListState = unwrap(coreExport('forum/states/DiscussionListState'));
      if (
        DiscussionListState &&
        DiscussionListState.prototype &&
        typeof DiscussionListState.prototype.sortMap === 'function' &&
        typeof override === 'function'
      ) {
        override(DiscussionListState.prototype, 'sortMap', function (original) {
          var map = original.call(this);
          if (map && typeof map === 'object') {
            delete map.hot;
            delete map.votes;
          }
          return map;
        });
      }
    } catch (e) {}

    try {
      var Post = unwrap(coreExport('forum/components/Post'));
      if (Post && Post.prototype && Post.prototype.actionItems) {
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
  });

  if (typeof module !== 'undefined') {
    module.exports = {};
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
