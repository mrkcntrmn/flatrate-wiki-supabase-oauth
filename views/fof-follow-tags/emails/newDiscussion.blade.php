{!! $translator->trans('flatrate-email-policy.email.follow_tags.new_discussion.body', [
'{recipient_display_name}' => $user->display_name,
'{actor_display_name}' => $blueprint->discussion->user->display_name,
'{title}' => $blueprint->discussion->title,
'{url}' => $url->to('forum')->route('discussion', ['id' => $blueprint->discussion->id]),
]) !!}
