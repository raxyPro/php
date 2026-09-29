# rctask — task manager (PHP + MySQL)

See FEATURES.md for a full description of what the app does today.

The PHP + MySQL edition of rcfamily. Type what needs doing in plain words; Claude (or built-in rules) splits it into tasks and puts each one in a **bandwidth**, the slot of your week when you can actually do it: Workday, Early morning, Weekday evening, Weekend, On the go. Bandwidths are editable (name, description, days, time window, hours per week).

No frameworks, no Composer, no build step: plain PHP 8.1+, PDO, MySQL/MariaDB and vanilla JavaScript.

## Requirements

- PHP **8.1 or newer** with `pdo_mysql`, `curl`, `dom`, `mbstring` (all on by default in XAMPP)
- MySQL 8+ or MariaDB 10.4+
- Optional: a Claude API key from https://console.anthropic.com for AI sorting

## Set up on Windows (XAMPP)

1. **Create the database and tables.** Start MySQL in the XAMPP Control Panel. Create a database named `rctask` (utf8mb4), then load the tables into it:
   ```powershell
   cd C:\Users\Hp\Dropbox\AppDev\php\rctask
   C:\xampp\mysql\bin\mysql.exe -u root -p -e "CREATE DATABASE IF NOT EXISTS rctask CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:\xampp\mysql\bin\mysql.exe -u root -p rctask < sql\schema.sql
   ```
   (Or in phpMyAdmin: create `rctask`, select it → Import → choose `sql/schema.sql`.) `sql/schema.sql` only creates tables; it does not create the database.

2. **Configure.** Copy `config.sample.php` to `config.php` and set the database user/password. Paste your Claude API key into `anthropic_api_key` if you have one.

3. **Run it.** Either:
   - **PHP's built-in server** (simplest):
     ```powershell
     C:\xampp\php\php.exe -S localhost:8080 -t public
     ```
     Open http://localhost:8080
   - **Apache (XAMPP):** add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, then restart Apache:
     ```apache
     Alias /rctask "C:/Users/Hp/Dropbox/AppDev/php/rctask/public"
     <Directory "C:/Users/Hp/Dropbox/AppDev/php/rctask/public">
         Require all granted
         AllowOverride All
     </Directory>
     ```
     Open http://localhost/rctask/

4. **Create your account** on the sign-in page (“Create an account”). Then set `'allow_register' => false` in `config.php` so nobody else can sign up.

## How it works

- `public/index.php` renders the page; `public/assets/app.js` does the UI and calls `public/api.php`.
- With an API key, **Create tasks** sends your text to `api.php?a=parse`, which calls Claude from the server (`src/Claude.php`). The key never reaches the browser.
- Without a key, or if Claude fails, the browser falls back to built-in keyword rules (dates like `10-Sep-26`, `by Tuesday`, `tonight`, `this weekend`).
- You review the suggested tasks, then **Add** saves them to MySQL.
- Notes are rich text; the server cleans them with an allow-list (`src/Sanitizer.php`), so scripts or unsafe HTML never get stored.
- Every API write is protected by a CSRF token, and every query is scoped to the signed-in user.

## Layout

```
rctask/
  config.sample.php      copy to config.php (DB, Claude key, timezone, sign-up switch)
  sql/schema.sql         users, bandwidths, entries tables (database: rctask)
  public/                web root — point the server here
    index.php            app page (requires sign-in)
    login.php, register.php, logout.php
    api.php              JSON API
    manifest.webmanifest “Add to Home Screen” support
    assets/app.js        front end
    assets/app.css       styles (light and dark)
    assets/icon.svg
  src/                   not web-accessible
    bootstrap.php        config, session, PDO, helpers
    Tasks.php            task CRUD
    Bandwidths.php       defaults + save
    Claude.php           Claude API call and prompt
    Sanitizer.php        rich-text HTML cleaner
    auth_layout.php      shared head for sign-in pages
```

## API

| Method | `api.php?a=` | Body | Returns |
|---|---|---|---|
| GET | `bootstrap` | – | `{tasks, bandwidths, ai}` |
| GET | `tasks` | – | `{tasks}` |
| POST | `task_save` | task object | `{task}` |
| POST | `task_delete` | `{id}` | `{ok}` |
| POST | `bandwidths_save` | `{list}` | `{bandwidths}` |
| POST | `parse` | `{text}` | `{ok, tasks}` or `{ok:false, reason}` |

POST requests need the `X-CSRF-Token` header (the page supplies it).

## Same data model as the React edition

The `entries` table already has the columns for events and expenses (`kind`, `event_type`, `event_date`, `planned_due`, `amount`, `pay_mode`, `remark`), so the next steps from `rcfamily/readme.txt` can be added without schema changes:

1. Events: log what happened, with date and remark.
2. Completed tasks become **planned events**.
3. Expenses with amounts and monthly totals.
