# ProjectDesk (PHP + MySQL)

A simple project and task tracker. Projects have one level of tasks. The Admin sees everything; a User sees only his own tasks and the projects they belong to.

## Requirements
- PHP 7.4 or newer with the `pdo_mysql` extension
- MySQL 5.7+/8 or MariaDB 10.3+
- Database `rcpro`. All tables use the `pd_` prefix: `pd_users`, `pd_projects`, `pd_tasks`.

## Install
1. Copy `config.sample.php` to `config.php` and set the host, port, user and password.
   Your local MySQL runs on port 3406. `config.php` is listed in `.gitignore`.
2. Point your web server at this folder, or run it locally:
   `php -S localhost:8080 -t .` and then open http://localhost:8080
3. The first visit opens `setup.php`. It creates the tables and your Admin account.
   You can tick the option to load sample data; the demo users' password is `demo1234`.
   Once a user exists, setup locks itself, and you can delete `setup.php` from the server.

## Files
| File | Purpose |
|---|---|
| index.php | Dashboard: project cards + tasks (Today / Next Week / All) |
| api.php | Handles every save: tasks, status, projects, users, password |
| users.php | User management (Admin) |
| account.php | Change own password |
| export.php | CSV export of visible tasks |
| login.php / logout.php / setup.php | Sign in, sign out, first-time install |
| lib/bootstrap.php | Config, DB (PDO), session, CSRF, auth helpers |
| lib/layout.php | Ribbon, dialogs, status bar |
| schema.sql | Table definitions |

## Rules
- **Admin** creates and edits projects, tasks and users, and sees everything. He can filter tasks by owner.
- **User** sees projects where he has at least one task, and only his own tasks. He can change the status and remark on those tasks.
- Users are deactivated, not deleted. There must always be at least one active Admin.
- Deleting a project also deletes its tasks.
- Next Week means the coming Monday to Sunday.
- Overdue means the target date has passed and the task is not Completed.

## Security
- Passwords are hashed with bcrypt (`password_hash`).
- All SQL uses prepared statements.
- Every form has a CSRF token.
- Output is HTML-escaped.
- Session cookies are httponly and SameSite=Lax.
