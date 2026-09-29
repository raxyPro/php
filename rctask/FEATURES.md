# rctask — Feature description (current app, as of 27-Sep-2026)

This describes what the app does **today**, so it can be amended later.
Mark changes with ~~strike~~ / **NEW** / **CHANGE** as you edit.

---

## 1. Overview

A personal task manager. You type what needs doing in plain words; the app
splits it into separate tasks, suggests a due date, priority, effort and a
**bandwidth** (the slot of your week when you can realistically do it), lets
you review them, then saves them to MySQL.

- **Stack:** PHP 8.1+ (no framework, no Composer), PDO + MySQL 8 / MariaDB 10.4+, vanilla JavaScript, custom CSS (no Bootstrap).
- **Database name:** `rctask`
- **Users:** multi-user with sign-in; every query is scoped to the signed-in user.

---

## 2. Sign-in and accounts

| # | Feature | Detail |
|---|---|---|
| 2.1 | Sign in | Email + password (`public/login.php`). Passwords stored with `password_hash()`. |
| 2.2 | Create account | `public/register.php`. Name (optional), email, password (8+ chars). Duplicate email is rejected. |
| 2.3 | Close sign-ups | `allow_register => false` in `config.php` blocks the register page. |
| 2.4 | Sign out | `public/logout.php`. |
| 2.5 | Session | 30-day cookie, HttpOnly, SameSite=Lax. Session ID regenerated on sign-in. |
| 2.6 | Default bandwidths | A new account gets the 5 default bandwidths (see §5). |

---

## 3. Adding tasks from plain text

| # | Feature | Detail |
|---|---|---|
| 3.1 | Compose box | Type one line or several, e.g. *"visit doctor by this Tuesday, fix the bathroom tap"*. **Ctrl+Enter** or **Create tasks**. |
| 3.2 | Example prompts | Two "Try:" buttons fill the box with sample text. |
| 3.3 | AI parsing (Claude) | If `anthropic_api_key` is set in `config.php`, the text is sent from the **server** to Claude (`src/Claude.php`, default model `claude-haiku-4-5`). The key never reaches the browser. |
| 3.4 | What Claude returns per task | title (starts with a verb, no date words), bandwidth, due date, priority, effort (minutes), category, person, "why" (reason for bandwidth). |
| 3.5 | Built-in rules (fallback) | Used when no API key, Claude fails, or Claude finds nothing. Runs in the browser (`app.js → ruleParse`). |
| 3.6 | Splitting (rules) | On new lines, `;`, bullets `•`, commas, and "and" before a verb (call, pay, fix, book, send…). Max 25 tasks. |
| 3.7 | Dates understood (rules) | `10-Sep-26`, `10 Sep 2026`, `10/09/2026` (day-month-year), `today`, `tonight`, `tomorrow`/`tmrw`, weekday names (`by Tuesday`, `next Friday`), `this/next weekend`. Prefixes: before, by, on, due, until, till, latest by, this, next. |
| 3.8 | Title clean-up (rules) | Removes the date phrase and lead-ins like "remind me to", "need to", "don't forget to". |
| 3.9 | Priority (rules) | High = urgent/asap/important/critical or due within 3 days; Low = someday/sometime/optional; else Medium. |
| 3.10 | Bandwidth guess (rules) | Keyword rules, e.g. office/client/deck → Workday; call/pay/book → On the go; walk/gym → Early morning; shop/doctor/garage → Weekend; fix/clean/home → Weekday evening. |
| 3.11 | Category guess (rules) | Work, Vehicle, Health, Finance, Home, Family, Shopping, else Personal. |
| 3.12 | Effort default (rules) | By bandwidth: On the go 10m, Early morning 30m, Workday 60m, Evening 45m, Weekend 90m. |
| 3.13 | Review step | Suggested tasks appear as editable rows (title, bandwidth, priority, due date, minutes). Remove any row with ×. Shows whether Claude or rules sorted them. |
| 3.14 | Add / Discard | **Add N tasks** saves them (status "To do", original text kept as `source_text`). **Discard** throws the drafts away. |

---

## 4. Task list

| # | Feature | Detail |
|---|---|---|
| 4.1 | Layout | One table: tick box · Task (with status/category/person tags) · Bandwidth · Priority · Due · Effort. |
| 4.2 | Status filter | **Open** (not Done) / **Done** / **All**. |
| 4.3 | Bandwidth filter | Click a bandwidth card to show only its tasks; "Show all" clears it. |
| 4.4 | Search | Matches title, category, person, original text, notes, bandwidth name. |
| 4.5 | Sort | Due date, Priority, Bandwidth, Effort, Title, Status, Category, Date added; ascending/descending; click column headers too. Tasks with no date go last. |
| 4.6 | Due labels | Date shown as `dd-Mon-yy` with "Nd overdue", "Today", "Tomorrow", "In N days" (≤7) for open tasks. |
| 4.7 | Quick complete | Tick box toggles Done ↔ To do. |
| 4.8 | Remembered view | Sort, direction, status filter and bandwidth filter saved in browser localStorage. |
| 4.9 | Refresh | Reloads tasks when you return to the browser tab (picks up changes from another device). |
| 4.10 | **Not present** | No Today / This week / Later grouping. |

---

## 5. Bandwidths (slots of the week)

| # | Feature | Detail |
|---|---|---|
| 5.1 | Defaults | Workday (Mon–Fri 9–18, 10 h/wk), Early morning (Mon–Fri 6–9, 3 h), Weekday evening (Mon–Fri 18–22:30, 7.5 h), Weekend (Sat–Sun 7–22, 8 h), On the go (no fixed time, 2 h). |
| 5.2 | Cards | Each card shows name, description, open task count, total effort, and a load meter: effort of open tasks due in the next 7 days vs. weekly hours ("over" when exceeded). |
| 5.3 | "Now" indicator | Header pill and card badge show which bandwidth matches the current day/time. Updates every minute. |
| 5.4 | Edit bandwidths | Rename, description (Claude reads it to place tasks), days, start/end time, hours per week, colour auto-assigned. Add new ones. |
| 5.5 | Delete rule | A bandwidth that still has tasks cannot be removed. At least one must remain. |

---

## 6. Task editor (click a task)

| Field | Type | Notes |
|---|---|---|
| Task | text (≤300) | required |
| Bandwidth | dropdown | help text shows the bandwidth description |
| Status | dropdown | **To do / In progress / Waiting / Done** |
| Priority | dropdown | High / Medium / Low |
| Effort | minutes (5–10000) | |
| Due date | date | "No date" button clears it |
| Category | text + suggestions | Work, Home, Family, Health, Finance, Vehicle, Shopping, Personal, Admin |
| Person | text (≤80) | |
| Notes | **rich text** | Bold, Italic, Underline, Heading, bullet list, numbered list, Clear. Paste is plain text. |

- Header shows date added, original text, and "why this bandwidth".
- **Delete** needs two taps. **Esc** or clicking outside closes.
- Setting status to Done records `completed_at`; moving back clears it.
- **Not present:** time of day, reminders, Cancelled status, separate Progress and Remark rich-text fields.

---

## 7. Data model (database `rctask`)

- **users** — id, email (unique), name, password_hash, created_at.
- **bandwidths** — per user: id, name, description, hours_per_week, hue, days (CSV 0–6, 0=Sun), start_time, end_time, sort_order.
- **entries** — tasks now (`kind='task'`); events and expenses reserved for later.
  - Used today: title, bandwidth_id, status, priority, due_date, effort_min, category, person, notes_html, why, source_text, created_at, updated_at, completed_at.
  - Reserved / unused today: kind `event`/`expense`, event_type, event_date, planned_due, amount, pay_mode, remark (plain VARCHAR 500).

---

## 8. Architecture

```
public/            web root
  index.php        main page (HTML shell)
  api.php          JSON API (router)
  login.php, register.php, logout.php
  assets/app.js    all UI logic + rule parser
  assets/app.css   styles (light + dark)
src/               not web-accessible
  bootstrap.php    config, session, db() PDO helper, CSRF, json_out()
  Tasks.php        task list/save/delete (SQL inside)
  Bandwidths.php   defaults, list, save
  Claude.php       Claude API call + prompt
  Sanitizer.php    rich-text HTML allow-list cleaner
sql/schema.sql     table definitions
```

### API (`public/api.php?a=`)

| Method | Action | Body | Returns |
|---|---|---|---|
| GET | `bootstrap` | – | `{tasks, bandwidths, ai}` |
| GET | `tasks` | – | `{tasks}` |
| POST | `task_save` | task object | `{task}` |
| POST | `task_delete` | `{id}` | `{ok}` |
| POST | `bandwidths_save` | `{list}` | `{bandwidths}` |
| POST | `parse` | `{text}` | `{ok, tasks}` or `{ok:false, reason}` |

---

## 9. Security

- CSRF token required on every POST (header `X-CSRF-Token` for API, hidden field for forms).
- All SQL uses prepared statements; every query filtered by `user_id`.
- Rich-text notes cleaned server-side: only p, br, b, strong, i, em, u, ul, ol, li, h3, div, span; all attributes removed; script/style/iframe etc. dropped.
- `src/`, `sql/`, `config.php` blocked from the web by `.htaccess`.
- Claude API key stays on the server.

---

## 10. Other

- **PWA (installable app):**
  - `public/manifest.webmanifest` — name "rctask", standalone window, PNG icons (192, 512, maskable), "Add tasks" shortcut.
  - `public/sw.js` — service worker. App files cached; pages and `api.php` reads are network-first with the last copy used offline (view-only); saves/deletes/AI parse always go to the server.
  - `public/offline.html` — shown if the app was never loaded on the device and there's no connection.
  - "Install app" button in the header (Chrome/Edge/Android); iPhone: Share → Add to Home Screen.
  - Offline / back-online notice.
  - Sign-out clears cached data on that device (`Clear-Site-Data`).
  - Needs HTTPS (or `localhost`) for install and the service worker.
  - When you change `app.js`/`app.css`, bump `?v=` in `index.php` and `VERSION` + the `SHELL` list in `sw.js`.
- Light and dark theme follow the system setting.
- Timezone from `config.php` (default Asia/Kolkata); MySQL session time zone set to match.

---

## 11. Gaps against the target spec (to amend)

- [ ] Time field (for reminders)
- [ ] Status set: new / progress / completed / cancelled
- [ ] Progress — rich text
- [ ] Remark — rich text
- [ ] List grouped as Today / This week / Later
- [ ] HTML5 + Bootstrap front end
- [ ] Separate database class/layer (currently `db()` helper + SQL in service classes)
- [ ] Decide: keep or drop bandwidths, priority, effort, category, person
