# rcexe (rc execute) — Features, v1.1 (29-Sep-2026)

The same content is shown in the app at **About** (`public/about.php`).
Mark changes with ~~strike~~ / **NEW** / **CHANGE** as you edit.

## 1. Concepts
| Term | Meaning | Example |
|---|---|---|
| Mission | Short-term goal / small project made of several tasks; target date, colour, progress | File ITR, Buy EV |
| Task | One action: description, date, time, status, progress, remark; may belong to a mission | Find soft-skill trainer for Raghu |
| Idea | Thought / lesson / quote, not an action; can become a task | Gold vs silver: no one remembers who came second |
| Bandwidth | A slot of the week; each user defines their own | Workday, Weekday evening, Weekend, On the go |

## 2. Adding (one text box)
- Compact one-line box; expands when tapped. Ctrl+Enter = Create.
- `Visit doctor by this Tuesday at 6pm` → task with date + time; the bandwidth covering Tue 18:00 is chosen.
- `File ITR: collect Form 16, call CA, verify 26AS` → new mission + 3 tasks (also `Mission Buy EV: …`). An existing mission with the same name is reused.
- `idea: …` → idea.
- A mission selected in the sidebar → new tasks go into it.
- Dates: 10-Oct-26, 10/10/2026, today, tonight, tomorrow, weekday names (by this Tuesday, next Friday), this/next weekend. Times: 6pm, 10:30am, at 18:00.
- Claude (if API key set) returns missions, tasks and ideas; built-in rules otherwise. Review screen before saving (edit mission, date, time, bandwidth; remove rows).

## 3. Tasks
- Fields: description, mission, status (New / Progress / Completed / Cancelled), date, time, Progress (rich text), Remark (rich text); under "More": bandwidth, priority, effort, category, person.
- Open view grouped **Today** (incl. overdue) / **This week** (next 7 days) / **Later** (incl. no date), sorted by date, time, priority.
- "in 25m" / "passed" for today's timed tasks; overdue flagged.
- Tick = Completed (tick again = New). Views: Open, Completed, Cancelled, Ideas. Search covers title, progress, remark, mission, bandwidth, person.

## 4. Missions (top left; chip row on phones)
- Each shows done/total, progress bar, days left or late.
- Click → only its tasks + header with goal, target, progress, "+ Task", Edit.
- Edit: name, goal, target date, status (Active / Completed / Cancelled), colour. Delete keeps its tasks.
- Completed & cancelled missions collapsed at the bottom of the list.

## 5. Ideas
- Card view with note (rich text) and tag; searchable; "Make it a task".

## 6. Bandwidths (bottom of page)
- Per user: name, description, days, time window, hours/week; add/remove; "Load defaults".
- Cards: open count, effort due in 7 days vs weekly hours, "Now" marker; click to filter.

## 7. App
- Name rcexe; folder and database stay `rctask`.
- PWA (installable, offline reading), DEV/LIVE marker, sign-in per user, CSRF, server-side rich-text cleaning.
- Version in `src/bootstrap.php` (`APP_VERSION`); CSS/JS cache-busted by version; service worker `rcexe-v1.1`.

## 8. Data model
- `missions`: id, user_id, title, goal, target_date, status, hue, sort_order, timestamps, completed_at.
- `entries`: kind task|idea|event|expense, mission_id (FK, SET NULL), title, status, due_date, due_time, progress_html, remark_html, notes_html (idea), bandwidth_id, priority, effort_min, category, person, …
- `bandwidths`, `users` unchanged.

## 9. API (`public/api.php?a=`)
GET `bootstrap`, `entries` · POST `entry_save`, `entry_delete`, `mission_save`, `mission_delete`, `bandwidths_save`, `parse`.

## 10. Release history
- **1.1 — 29-Sep-2026** — renamed rcexe; missions; ideas; time, progress, remark; new statuses; Today/This week/Later; About page; denser layout; bandwidths at bottom and clearly per user. Migration: `sql/migrate_1.0_to_1.1.sql`.
- **1.0 — 27-Sep-2026** — rctask: plain-text tasks, bandwidths, PWA, DEV/LIVE, production config.

## 11. Next
- Reminders at task time · events and expenses · recurring tasks.
