# Sira AI API (v1)

Base URL: `https://<api-host>/api/v1`. All requests and responses are JSON (`Accept: application/json`).

## Authentication

Laravel Sanctum personal access tokens, suitable for mobile apps (no cookies).

- `POST /auth/register` `{name, email, password, password_confirmation, timezone?, device_name?}` → `201 {token, token_type, expires_at, user}`
- `POST /auth/login` `{email, password, device_name?}` → `{token, …}`
- `POST /auth/forgot-password` `{email}` → always `200` (no account enumeration)
- `POST /auth/reset-password` `{token, email, password, password_confirmation}` → revokes all tokens
- `POST /auth/logout` (current device) · `POST /auth/logout-all`

Send `Authorization: Bearer <token>` on every other call. Tokens expire after `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default 60 days). A `401` means sign in again.

Rate limits: auth endpoints allow 10 requests/min per email+IP, the API 120/min per user, and the AI coach 10/min per user on top of its daily quota.

## Errors

| Status | Body | Meaning |
| --- | --- | --- |
| 401 | `{message}` | Missing or expired token |
| 403 | `{message, code: "premium_required"}` | Premium-only feature |
| 403 | `{message, code: "plan_limit_reached"}` | Free plan limit: 3 active habits, 3 active goals, 5 tasks added per day (repeat occurrences don't count), 2 subtasks per task, 25-minute focus sessions |
| 403 | `{message}` | Resource belongs to another user |
| 409 | `{message}` / `{code: "billing_purchase_in_use"}` | ID collision or purchase linked to another account |
| 422 | `{message, errors: {field: [..]}}` | Validation |
| 429 | `{message, code: "ai_daily_limit_reached"}` | AI daily quota (or general throttling) |
| 503 | `{message, code: "ai_not_configured" \| "billing_not_configured" \| "billing_store_unavailable" \| "ai_unavailable"}` | Missing server credentials or provider outage |

## Account

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/me` | Profile, XP, level, `is_premium` |
| PATCH | `/me` | `name, email, timezone, onboarding_completed` |
| POST | `/me/avatar` | multipart `avatar` (jpg/png/webp, ≤ 2 MB, ≤ 4096 px); returns the user with `avatar_url` |
| DELETE | `/me/avatar` | Removes the profile photo |
| PUT | `/me/password` | `current_password, password, password_confirmation`; signs out other devices |
| GET | `/me/export` | Full JSON export of the user's data |
| DELETE | `/me` | `{password}`; permanently deletes the account and all data (cascades) |
| GET/PATCH | `/settings` | Theme, colour theme, notifications, default priority/due time, week start, focus/break minutes. `color_theme` other than `sira` needs Premium (403 `premium_required`), and so does `focus_minutes` other than 25 |

## Notifications

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/devices` | `{token, platform}` (`android` or `ios`); registers this phone for push. Tied to the API token, so `auth/logout` removes it |
| DELETE | `/devices` | `{token}` |
| GET | `/notifications?page=` | Inbox, newest first (30 per page): `{id, kind, title, body, route, icon, read_at, created_at}`; `meta.unread_count` |
| POST | `/notifications/read` | `{ids?: uuid[]}`; marks those (or all) read |
| DELETE | `/notifications/{id}` | Removes one entry |

Kinds: `new_sign_in`, `password_changed`, `subscription_activated`, `subscription_billing_issue`, `subscription_renewal_off`, `subscription_expired`, `weekly_review`, `achievement_unlocked` (inbox only). `route` is the app screen to open.

Notification settings (`PATCH /settings`): `notifications_enabled`, `task_reminders`, `reminder_lead_minutes` (0, 5, 10, 15, 30, 60), `habit_reminders`, `daily_summary`, `daily_summary_time`, `streak_reminders`, `streak_reminder_time`, `goal_reminders`, `push_enabled`, `weekly_review`. Reminders are scheduled on the phone; `push_enabled` and `weekly_review` control what the server sends.

## Tasks

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/tasks` | Filters: `view=today\|upcoming\|completed\|overdue`, `date`, `from`, `to`, `category_id`, `goal_id`, `priority`, `status=open\|completed`, `search`, `updated_since`; paginated (`per_page` ≤ 100) |
| POST | `/tasks` | `title` required; `id` (client UUID, optional), `description, priority, category_id, goal_id, due_date, start_time, end_time, repeat_rule (none\|daily\|weekdays\|weekly\|monthly), repeat_until, add_to_calendar, position, completed, subtasks[{id?, title, completed?}]` |
| GET/PATCH/DELETE | `/tasks/{id}` | PATCH accepts the same fields; `subtasks` replaces the list |
| POST | `/tasks/{id}/complete` | Awards XP (low 5 / medium 10 / high 15), spawns the next occurrence of a recurring task, updates linked goal progress → `{data, xp, achievements_unlocked}` |
| POST | `/tasks/{id}/reopen` | Reverses completion XP |
| POST | `/tasks/{id}/subtasks` · PATCH/DELETE `/tasks/{id}/subtasks/{subId}` | Subtask completion awards 2 XP |

Categories: `GET/POST /categories`, `PATCH/DELETE /categories/{id}`.

## Habits, goals, focus

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/habits?days=7` | Habits with recent check-ins and `current_streak` |
| POST/PATCH/DELETE | `/habits[/{id}]` | `name, icon, color, frequency, days_of_week[1-7], target_per_day, reminder_time, archived` · free plan: 3 active |
| POST | `/habits/{id}/check-ins` | `{date?, count?}` (not in the future) · +5 XP |
| DELETE | `/habits/{id}/check-ins/{YYYY-MM-DD}` | Undo |
| GET/POST/PATCH/DELETE | `/goals[/{id}]` | `title, description, target_date, progress, status` · free plan: 3 active · completing awards 50 XP |
| GET/POST/PATCH/DELETE | `/focus-sessions[/{id}]` | `started_at, ended_at, planned_seconds, duration_seconds, completed, task_id, type (focus\|break)` · 1 XP per 5 focused minutes (max 30) |

## Calendar: events, birthdays, timetable

All free. Each row type supports `GET` (list), `POST` (create, idempotent with a client `id`), `PATCH /{id}` and `DELETE /{id}`.

| Path | Fields |
| --- | --- |
| `/events` | `title, description, location, all_day, start_date, start_time, end_date, end_time, repeat_rule (none\|daily\|weekly\|monthly\|yearly), repeat_until, reminder_minutes[], color, goal_id` · must end after it starts |
| `/birthdays` | `name, month, day, birth_year?, reminder_days[] (0 = on the day), reminder_time, color, note` · 29 February allowed |
| `/timetable/terms` | `name, starts_on, ends_on` · a term's classes repeat weekly between these dates |
| `/timetable/classes` | `term_id?, name, code, kind (lecture\|tutorial\|lab\|seminar\|other), lecturer, room, color, slots[{weekday 1=Mon…7=Sun, start, end}], reminder_minutes, show_in_calendar, notes` |

## Read models

| Method | Path | Plan |
| --- | --- | --- |
| GET | `/overview?date=` | Today's tasks (with overdue), progress, habit counts, XP/level/streak | Free |
| GET | `/calendar?from=&to=` | Calendar tasks + habit completions per day (≤ 62 days) | Free |
| GET | `/analytics?from=&to=` | Per-day completions and focus minutes, category split | Free: 7 days · Premium: 366 |
| GET | `/gamification` | XP, level, streak, achievement catalog with progress, recent XP | Free |
| GET | `/analytics/advanced` | Completion and on-time rate, trends, by hour/weekday, habit consistency | Premium |
| GET | `/reports/productivity?format=json\|csv` | Summary + advanced + highlights, or CSV | Premium |

## Sync (offline support)

`GET /sync?since=<server_time>` returns `{server_time, full, tasks, categories, habits, goals, focus_sessions, events, birthdays, timetable_terms, timetable_classes, settings}`.
Without `since` you get a full snapshot: open tasks plus 60 days of history (repeating events always, one-off events that ended in the last 60 days). With `since` you get only the rows that changed, including soft-deleted rows that have `deleted_at` set.

Writes use the normal endpoints. Clients generate UUIDs, so replaying a queued `POST` is idempotent: the existing row is updated (`200`) instead of duplicated. Conflicts resolve last-write-wins.

## Subscriptions

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/subscription` | Entitlements: plan, features, limits, active subscription, product ids |
| POST | `/subscription/verify` | `{platform: google_play\|app_store\|revenuecat, product_id, purchase_token}` → verified with the store or RevenueCat before premium is granted |
| POST | `/webhooks/google-play?token=` | Play Real-time Developer Notifications (Pub/Sub push) |
| POST | `/webhooks/app-store` | App Store Server Notifications V2, re-verified with Apple's API |
| POST | `/webhooks/revenuecat` | `Authorization: Bearer <REVENUECAT_WEBHOOK_AUTH>` |

A purchase token can only be linked to one account. Expired subscriptions stop premium features but never delete data. `php artisan sira:subscriptions:refresh` (scheduled hourly) re-checks renewals and expiries.

## AI coach (Premium)

All coach calls are server-side; the provider key never reaches the app. Each user gets `SIRA_AI_DAILY_LIMIT` requests per day (default 50). A failed provider call doesn't count toward the limit.

| Method | Path | Body → result |
| --- | --- | --- |
| GET / DELETE | `/coach/messages` | Chat history (last 50) and `usage` |
| POST | `/coach/chat` | `{message}` → `{reply}` |
| POST | `/coach/breakdown` | `{goal}` or `{goal_id}` → `{summary, tasks[{title, priority, estimate_minutes, due_in_days}]}` |
| POST | `/coach/prioritize` | → `{summary, suggestions[{task_id, priority, reason}]}` (only the user's own task ids) |
| POST | `/coach/plan-day` | `{date?}` → `{summary, blocks[{start, end, title, task_id, kind}]}` |
