/*! FlatRate plain upvotes through the supported Flarum 2 resource API. */
(function () {
  'use strict';
  if (typeof app === 'undefined') return;
  app.initializers.add('flatrate-native-voting', function () {
    var extension = flarum.reg.get('core', 'common/extend');
    if (!extension || typeof extension.extend !== 'function') return;
    function onClass(id, fn) {
      var loaded = flarum.reg.get('core', id);
      if (loaded) fn(loaded.default || loaded);
      else flarum.reg.onLoad('core', id, function (module) { fn(module.default || module); });
    }
    function enabled() { return !!app.forum.attribute('flatRateVotingEnabled'); }
    function send(component, post, value) {
      if (component.flatRateVoteBusy) return;
      component.flatRateVoteBusy = true;
      m.redraw();
      return post.save({ flatRateVote: value }).then(function () {
        var discussion = post.discussion();
        return Promise.all([
          app.store.find('discussions', discussion.id()),
          app.request({ method: 'GET', url: app.forum.attribute('apiUrl') }).then(function (payload) {
            if (payload.data && payload.data.attributes) app.forum.pushAttributes(payload.data.attributes);
          }),
        ]);
      }).finally(function () { component.flatRateVoteBusy = false; m.redraw(); });
    }
    function button(component, post) {
      var model = post;
      var count = Number(model.attribute('flatRatePostUpvotes')) || 0;
      var mine = !!model.attribute('flatRatePostViewerUpvoted');
      var can = !!model.attribute('flatRatePostCanUpvote');
      var label = mine ? 'Remove upvote' : 'Upvote';
      return m('button', {
        type: 'button', className: 'Button Button--link FlatRateNativeVote' + (mine ? ' FlatRateNativeVote--mine' : ''),
        'aria-label': label, 'aria-pressed': String(mine), title: label,
        disabled: !can || component.flatRateVoteBusy,
        onclick: function (event) { event.preventDefault(); return send(component, post, mine ? 0 : 1); },
      }, [m('i', { className: 'icon fas fa-thumbs-up', 'aria-hidden': 'true' }), m('span.FlatRateNativeVote-count', String(count))]);
    }
    onClass('forum/components/CommentPost', function (CommentPost) {
      extension.extend(CommentPost.prototype, 'actionItems', function (items) {
        if (!enabled()) return;
        items.add('votes', button(this, this.attrs.post), 80);
      });
    });
    onClass('forum/components/DiscussionListItem', function (DiscussionListItem) {
      ['oncreate', 'onupdate'].forEach(function (hook) {
        extension.extend(DiscussionListItem.prototype, hook, function (vnode) {
          var root = this.element || (vnode && vnode.dom);
          var title = root && root.querySelector('.DiscussionListItem-title');
          if (!title) return;
          var count = enabled() ? Number(this.attrs.discussion.attribute('flatRateDiscussionUpvotes')) || 0 : 0;
          var total = title.querySelector('.FlatRateDiscussionListTotal');
          if (!count) { if (total) total.remove(); return; }
          if (!total) { total = document.createElement('span'); total.className = 'FlatRateDiscussionListTotal'; title.appendChild(total); }
          total.textContent = String(count);
          total.setAttribute('aria-label', 'Discussion total: ' + count + ' upvotes');
        });
      });
    });
  });
})();

if (typeof module !== 'undefined') module.exports = {};
