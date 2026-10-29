(function () {
  'use strict';
  if (typeof app === 'undefined') return;
  app.initializers.add('flatrate-native-voting-admin', function () {
    (app.registry || app.extensionData).for('flatrate-wiki-supabase-oauth')
      .registerSetting({ setting: 'flatrate-voting.enabled', type: 'bool', label: 'Enable plain upvotes', help: 'Members with upvote permission can endorse other members’ visible posts. Rankings and voter lists stay unavailable.' })
      .registerPermission({ icon: 'fas fa-thumbs-up', label: 'Upvote posts', permission: 'discussion.votePosts' }, 'reply');
  });
})();

if (typeof module !== 'undefined') module.exports = {};
