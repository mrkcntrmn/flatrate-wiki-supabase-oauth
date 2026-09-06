# FORUM-SUB-001 pinned upstream fixtures

Exact APIs depended on for GM/CDJR family notification inheritance.

## Flarum core 1.8.19

| Fixture | Upstream path | Provenance |
|---|---|---|
| `flarum-1.8.19-NotificationSyncer.php` | `src/Notification/NotificationSyncer.php` | flarum/core 1.8.19 |
| `flarum-1.8.19-Extend-Conditional.php` | `src/Extend/Conditional.php` | flarum/core 1.8.19 |
| `flarum-1.8.19-NotificationServiceProvider.php` | `src/Notification/NotificationServiceProvider.php` | flarum/core 1.8.19 |

Notes:
- `NotificationSyncer::sync()` reconciles existing rows into `toDelete` /
  `toUndelete` / `newRecipients` **before** invoking `beforeSending` callbacks.
- `NotificationServiceProvider` does **not** bind `NotificationSyncer::class`.
- `Extend\Conditional::whenExtensionEnabled()` is the supported conditional API.

## FoF Follow Tags 1.3.0

| Fixture | Upstream path |
|---|---|
| `fof-follow-tags-1.3.0/SendNotificationWhenDiscussionIsStarted.php` | `src/Jobs/SendNotificationWhenDiscussionIsStarted.php` |
| `fof-follow-tags-1.3.0/SendNotificationWhenReplyIsPosted.php` | `src/Jobs/SendNotificationWhenReplyIsPosted.php` |
| `fof-follow-tags-1.3.0/SendNotificationWhenDiscussionIsReTagged.php` | `src/Jobs/SendNotificationWhenDiscussionIsReTagged.php` |
| `fof-follow-tags-1.3.0/NotificationJob.php` | `src/Jobs/NotificationJob.php` |
| `fof-follow-tags-1.3.0/NewDiscussionBlueprint.php` | `src/Notifications/NewDiscussionBlueprint.php` |
| `fof-follow-tags-1.3.0/NewPostBlueprint.php` | `src/Notifications/NewPostBlueprint.php` |
| `fof-follow-tags-1.3.0/NewDiscussionTagBlueprint.php` | `src/Notifications/NewDiscussionTagBlueprint.php` |
| `fof-follow-tags-1.3.0/PreventMentionNotificationsFromIgnoredTags.php` | `src/Listeners/PreventMentionNotificationsFromIgnoredTags.php` |
| `fof-follow-tags-1.3.0/ChangeTagSubscription.php` | `src/Controllers/ChangeTagSubscription.php` |
| `fof-follow-tags-1.3.0/FollowTagsFilter.php` | `src/Search/FollowTagsFilter.php` |
| `fof-follow-tags-1.3.0/extend.php` | `extend.php` |

Upstream repository: https://github.com/FriendsOfFlarum/follow-tags  
Pinned version: `1.3.0`  
Source checkout SHA used for fixtures: `b546d17e15dbcb091fa455e2ef8cc075bcb16f3d`

Reply caught-up threshold (exact 1.3.0):

```text
discussion_user.last_read_post_number >= lastPostNumber - 1
```

where the reply job is constructed with `lastPostNumber = post.number - 1`.

See `SHA256SUMS.txt` for per-file digests.
