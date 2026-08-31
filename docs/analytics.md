# Forum analytics contract

`forum.flatrate.wiki` uses the same GA4 property as FlatRate.wiki. Normal Flarum SPA/history navigation remains standard `page_view` measurement. This extension owns only FlatRate-specific forum behavior events that need authoritative Flarum persistence boundaries.

## Principle

Forum analytics should answer whether technicians are finding, following, and contributing useful repair knowledge. It must not export forum identity or content.

Do not use an analytics integration that sends the signed-in Flarum user ID to GA4.

## Privacy boundary

Never send:

- Flarum user/account IDs or routing usernames;
- FlatRate `tech_*` handles or nicknames;
- discussion/post IDs or URLs;
- discussion titles, reply text, search terms, or other free-form content;
- email addresses or phone numbers;
- Supabase/OAuth identifiers, session values, tokens, tickets, or bridge secrets;
- VINs, vehicle-private evidence, or arbitrary vehicle attributes;
- arbitrary error strings or tag strings.

Only bounded, reviewed dimensions may be emitted.

## Events

### Contribution outcomes

- `discussion_created` — after a new discussion successfully persists.
- `reply_created` — after a reply successfully persists.
- `job_breakdown_marked` — after the reply-level Job Breakdown marker successfully persists.

These are authoritative outcomes. Composer opens, submit clicks, optimistic client state, DOM mutation, and route changes are not valid success boundaries.

### Engagement diagnostics

- `forum_search` — after a forum search action is submitted; never send the query.
- `reaction_added` — after a supported reaction persists; omit reaction type unless a later bounded taxonomy is approved.
- `discussion_followed` — after follow/subscription state persists.
- `discussion_shared` — optional, only for a FlatRate-controlled share action with a clear completion boundary.

Engagement events are diagnostic by default and should not be GA4 key events merely because they are frequent.

## Dimensions

Allowed initial dimensions:

- `entry_point`: `forum` or `inspection` where the implementation can determine this safely from bounded application state.
- `contribution_type`: `discussion`, `reply`, or `job_breakdown` when useful.

Do not emit arbitrary tag names. A future bounded category taxonomy requires a separate privacy/cardinality review.

## Measurement hierarchy

```text
forum page_view
  -> forum_search / reaction_added / discussion_followed
  -> discussion_created / reply_created
  -> job_breakdown_marked
```

`job_breakdown_marked` is the strongest current community-value candidate because it represents structured technician knowledge rather than generic activity.

## Implementation rule

Provide one small FlatRate-owned analytics helper with a strict event/dimension allowlist. Invoke it only from reviewed Flarum success boundaries. Tests must prove sensitive/high-cardinality fields cannot reach the analytics call.

The existing Job Breakdown marker persistence owned by this extension is the canonical boundary for `job_breakdown_marked`.

## Delivery plan

1. Implement contribution outcomes first: `discussion_created`, `reply_created`, and `job_breakdown_marked`.
2. Verify those events in GA4 Realtime/DebugView before enabling any forum key-event configuration.
3. Promote `job_breakdown_marked` as the first forum key-event candidate once persistence and privacy proof pass.
4. Add engagement diagnostics next: `forum_search`, `reaction_added`, and `discussion_followed`.
5. Keep `discussion_shared` optional until FlatRate owns a stable share action and completion boundary.
6. Keep ordinary forum navigation as standard SPA/history `page_view`; never add duplicate custom page-view events.

## Acceptance

- normal GA4 SPA/history `page_view` measurement remains intact;
- no duplicate custom page-view events;
- no Flarum actor/user identifiers are sent;
- no discussion/post IDs, URLs, titles, body text, or search terms are sent;
- discussion/reply events fire only after persistence succeeds;
- Job Breakdown event fires only after marker persistence succeeds;
- retries/double-submit paths do not knowingly double-count one logical outcome;
- production GA4 Realtime/DebugView proof precedes key-event promotion.
