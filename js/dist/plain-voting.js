/*! FlatRate GROWTH-001B plain-voting foundation — UI suppression + gate-aware chrome. */
(function (root) {
  'use strict';

  if (typeof app === 'undefined') {
    if (typeof module !== 'undefined') {
      module.exports = {};
    }
    return;
  }

  // FoF Gamification reads layout flags from app.data at initializer time via
  // !!parseInt(...). Normalize before FoF (priority 0) so CommentPost keeps
  // votes in actionItems instead of registering the header alternate widget.
  // useAlternateLayout is intentionally NOT forced here: it also drives
  // discussion-list chrome; CommentPost ownership only requires altPostVotingUi=0.
  app.initializers.add(
    'flatrate-wiki-plain-voting-settings',
    function () {
      if (app.data && typeof app.data === 'object') {
        app.data['fof-gamification.upVotesOnly'] = '1';
        app.data['fof-gamification.iconName'] = 'thumbs';
        app.data['fof-gamification.altPostVotingUi'] = '0';
      }
    },
    100
  );

  app.initializers.add('flatrate-wiki-plain-voting', function () {
    // Re-assert after boot for render-time FoF setting() reads.
    if (app.data && typeof app.data === 'object') {
      app.data['fof-gamification.upVotesOnly'] = '1';
      app.data['fof-gamification.iconName'] = 'thumbs';
      app.data['fof-gamification.altPostVotingUi'] = '0';
    }

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

    function refreshDiscussionSummary(discussion) {
      if (!discussion || typeof discussion.id !== 'function') {
        return Promise.resolve(null);
      }
      return app.store.find('discussions', discussion.id()).catch(function () {
        return null;
      });
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

    // Flarum Mentions renders the "X replied" summary in CommentPost footerItems.
    // AbstractPost resolves footerItems before actionItems, so capture that VNode,
    // remove it from the footer, and re-home it into the action grid. This keeps
    // row 1 semantically owned by one ItemList instead of visually overlaying
    // two independent containers.
    try {
      var CommentPostLayout = unwrap(coreExport('forum/components/CommentPost'));
      if (
        CommentPostLayout &&
        CommentPostLayout.prototype &&
        CommentPostLayout.prototype.footerItems &&
        CommentPostLayout.prototype.actionItems
      ) {
        extend(CommentPostLayout.prototype, 'footerItems', function (items) {
          this.flatRateRepliedAction = null;
          if (
            items &&
            typeof items.has === 'function' &&
            typeof items.get === 'function' &&
            typeof items.remove === 'function' &&
            items.has('replies')
          ) {
            this.flatRateRepliedAction = items.get('replies');
            items.remove('replies');
          }
        });

        extend(CommentPostLayout.prototype, 'actionItems', function (items) {
          if (
            this.flatRateRepliedAction &&
            items &&
            typeof items.add === 'function'
          ) {
            items.add('flatRateReplied', this.flatRateRepliedAction, 90);
          }
        });
      }
    } catch (e) {}

    // After any FoF post vote mutation, reload discussion aggregate attributes.
    try {
      var PostModel = unwrap(coreExport('common/models/Post'));
      if (
        PostModel &&
        PostModel.prototype &&
        typeof PostModel.prototype.save === 'function' &&
        typeof override === 'function'
      ) {
        override(PostModel.prototype, 'save', function (original, data, options) {
          var result = original.call(this, data, options);
          var post = this;
          var isVote = Array.isArray(data) && data[2] === 'vote';
          if (!isVote || !votingEnabled()) {
            return result;
          }
          return Promise.resolve(result).then(function (saved) {
            var discussion =
              typeof post.discussion === 'function' ? post.discussion() : null;
            return refreshDiscussionSummary(discussion).then(function () {
              return saved;
            });
          });
        });
      }
    } catch (e) {}

    function decorateVoteChrome(root, model) {
      if (!root || !root.querySelector) {
        return;
      }
      var votesEl =
        root.querySelector('.CommentPost-votes') ||
        root.querySelector('.Post-votes') ||
        root.querySelector('.DiscussionListItem-votes');
      if (!votesEl) {
        return;
      }
      var count = 0;
      var mine = false;
      try {
        if (model && typeof model.votes === 'function') {
          count = Number(model.votes()) || 0;
        }
        if (model && typeof model.hasUpvoted === 'function') {
          mine = !!model.hasUpvoted();
        }
      } catch (err) {}
      votesEl.classList.toggle('FlatRateVotes--zero', count <= 0);
      votesEl.classList.toggle('FlatRateVotes--hasVotes', count > 0);
      votesEl.classList.toggle('FlatRateVotes--mine', mine);
    }

    function bindVoteChrome(Component, modelFrom) {
      if (!Component || !Component.prototype) {
        return;
      }
      ['oncreate', 'onupdate'].forEach(function (hook) {
        extend(Component.prototype, hook, function () {
          if (!votingEnabled()) {
            return;
          }
          var model = null;
          try {
            model = modelFrom(this);
          } catch (err) {
            model = null;
          }
          decorateVoteChrome(this.element, model);
        });
      });
    }

    try {
      var CommentPostVotes = unwrap(coreExport('forum/components/CommentPost'));
      bindVoteChrome(CommentPostVotes, function (cmp) {
        return cmp.attrs && cmp.attrs.post;
      });
    } catch (e) {}

    try {
      var DiscussionListItem = unwrap(coreExport('forum/components/DiscussionListItem'));
      bindVoteChrome(DiscussionListItem, function (cmp) {
        return cmp.attrs && cmp.attrs.discussion;
      });
    } catch (e) {}

    // Discussion-level aggregate opposite Following (SubscriptionMenu).
    // Do not subclass Flarum 1.8 Component: ES6 Component.initAttrs is static and
    // prototype-chain hacks throw TypeError, which blank the DiscussionPage stream.
    try {
      var DiscussionPage = unwrap(coreExport('forum/components/DiscussionPage'));
      if (
        DiscussionPage &&
        DiscussionPage.prototype &&
        typeof DiscussionPage.prototype.sidebarItems === 'function'
      ) {
        var discussionHeaderLoading = Object.create(null);

        function resolveFirstPost(discussion) {
          var discId = String(discussion.id());
          try {
            if (typeof discussion.firstPost === 'function') {
              var related = discussion.firstPost();
              if (related && typeof related.save === 'function') {
                return related;
              }
            }
          } catch (e) {}

          var firstId = null;
          try {
            firstId =
              discussion.attribute('firstPostId') ||
              discussion.attribute('first_post_id');
          } catch (e) {}
          if (!firstId) {
            try {
              var rel =
                discussion.data &&
                discussion.data.relationships &&
                discussion.data.relationships.firstPost &&
                discussion.data.relationships.firstPost.data;
              if (rel && !Array.isArray(rel) && rel.id) {
                firstId = rel.id;
              }
            } catch (e) {}
          }
          if (firstId) {
            var byId = app.store.getById('posts', String(firstId));
            if (byId && typeof byId.save === 'function') {
              return byId;
            }
          }

          try {
            var posts = app.store.all('posts') || [];
            for (var i = 0; i < posts.length; i++) {
              var post = posts[i];
              if (!post || typeof post.number !== 'function') {
                continue;
              }
              var parent =
                typeof post.discussion === 'function' ? post.discussion() : null;
              if (
                parent &&
                String(parent.id()) === discId &&
                Number(post.number()) === 1 &&
                typeof post.save === 'function'
              ) {
                return post;
              }
            }
          } catch (e) {}
          return null;
        }

        function upvoteDiscussionFirstPost(discussion) {
          var discId = discussion.id();
          var firstPost = resolveFirstPost(discussion);
          if (!firstPost) {
            return;
          }
          discussionHeaderLoading[discId] = true;
          m.redraw();
          var prevCount = Number(discussion.attribute('flatRateDiscussionUpvotes')) || 0;
          discussion.pushAttributes({
            flatRateDiscussionUpvotes: prevCount + 1,
            flatRateDiscussionViewerUpvoted: true,
            flatRateDiscussionCanUpvote: false,
            flatRateDiscussionViewerVotePostId: Number(firstPost.id()),
          });
          firstPost
            .save([true, false, 'vote'])
            .then(function () {
              return refreshDiscussionSummary(discussion);
            })
            .catch(function () {
              discussion.pushAttributes({
                flatRateDiscussionUpvotes: prevCount,
                flatRateDiscussionViewerUpvoted: false,
                flatRateDiscussionCanUpvote: true,
                flatRateDiscussionViewerVotePostId: null,
              });
            })
            .then(function () {
              discussionHeaderLoading[discId] = false;
              m.redraw();
            });
        }

        extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
          if (!votingEnabled()) {
            return;
          }
          var discussion = this.discussion;
          if (!discussion || !items || typeof items.add !== 'function') {
            return;
          }
          var discId = discussion.id();
          var count = Number(discussion.attribute('flatRateDiscussionUpvotes')) || 0;
          var mine = !!discussion.attribute('flatRateDiscussionViewerUpvoted');
          var canUpvote = !!discussion.attribute('flatRateDiscussionCanUpvote');
          var loading = !!discussionHeaderLoading[discId];
          var className =
            'FlatRateDiscussionVote Button Button--link' +
            (mine
              ? ' FlatRateDiscussionVote--mine'
              : ' FlatRateDiscussionVote--available');
          items.add(
            'flatRateDiscussionVote',
            m(
              'button',
              {
                className: className,
                type: 'button',
                disabled: loading || mine || !canUpvote,
                title: mine
                  ? 'You already endorsed this discussion'
                  : canUpvote
                    ? 'Upvote this discussion'
                    : 'Discussion upvotes',
                onclick: function (e) {
                  e.preventDefault();
                  e.stopPropagation();
                  if (
                    discussionHeaderLoading[discId] ||
                    !!discussion.attribute('flatRateDiscussionViewerUpvoted') ||
                    !discussion.attribute('flatRateDiscussionCanUpvote')
                  ) {
                    return;
                  }
                  upvoteDiscussionFirstPost(discussion);
                },
              },
              [
                m('span', { className: 'FlatRateDiscussionVote-count' }, String(count)),
                m('i', { className: 'icon fas fa-thumbs-up', 'aria-hidden': 'true' }),
              ]
            ),
            85
          );
        });
      }
    } catch (e) {}
  });

  if (typeof module !== 'undefined') {
    module.exports = {};
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
