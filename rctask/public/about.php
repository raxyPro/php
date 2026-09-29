<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$signedIn = current_user_id() !== null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= env_theme_color() ?>">
  <title><?= h(env_title_prefix()) ?>About · <?= APP_NAME ?></title>
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="env-<?= app_env() ?>">
<?= env_banner() ?>
<div class="about">
  <header>
    <h1><?= APP_NAME ?><?= env_badge() ?> <span class="ver">v<?= APP_VERSION ?></span></h1>
    <a class="btn small" href="<?= $signedIn ? 'index.php' : 'login.php' ?>">← Back to the app</a>
  </header>

  <section>
    <h2>What is rcexe?</h2>
    <p class="lead"><b>rcexe</b> means <b>rc execute</b>. It turns what is on your mind into things that actually get done: short-term <b>missions</b>, the <b>tasks</b> that move them forward, and <b>ideas</b> worth keeping.</p>
    <p>Type in plain words, the way you would tell a friend. rcexe splits it up, finds the dates and times, and places each task in the part of your week when you can realistically do it.</p>
  </section>

  <section>
    <h2>Goals</h2>
    <div class="goal-grid">
      <div><b>Capture in seconds</b>One text box for everything — no forms to fill before a task exists.</div>
      <div><b>Execute missions</b>Small projects like “File ITR” or “Buy EV” get finished, step by step, with visible progress.</div>
      <div><b>Plan by real time</b>Tasks sit in your own bandwidths (office hours, evenings, weekend…) so the plan fits your life.</div>
      <div><b>Never lose a thought</b>Ideas and lessons are kept separately from tasks and are easy to find again.</div>
      <div><b>Works everywhere</b>Browser, desktop and phone; installable as an app; readable offline.</div>
      <div><b>Private and simple</b>Your own data, your own server, plain PHP and MySQL.</div>
    </div>
  </section>

  <section>
    <h2>Concepts</h2>
    <table>
      <tr><th>Term</th><th>Meaning</th><th>Example</th></tr>
      <tr><td><b>Mission</b></td><td>A short-term goal or small project made of several tasks, with a target date and progress.</td><td>File ITR · Buy EV</td></tr>
      <tr><td><b>Task</b></td><td>One concrete action with a date, time, status, progress notes and remarks. It can belong to a mission.</td><td>Find soft-skill trainer for Raghu</td></tr>
      <tr><td><b>Idea</b></td><td>A thought, lesson or quote to remember — not an action. It can be turned into a task later.</td><td>Difference between gold and silver: no one remembers who came second</td></tr>
      <tr><td><b>Bandwidth</b></td><td>A slot of your week when you can do certain kinds of tasks. Every user defines their own.</td><td>Workday · Weekday evening · Weekend · On the go</td></tr>
    </table>
  </section>

  <section>
    <h2>Features</h2>
    <h3>Adding</h3>
    <ul>
      <li>One text box: <i>“Visit doctor by this Tuesday at 6pm”</i> becomes a task with date and time.</li>
      <li><i>“File ITR: collect Form 16, call CA, verify 26AS”</i> becomes a mission with three tasks. <i>“Mission Buy EV: …”</i> works too.</li>
      <li><i>“idea: …”</i> saves an idea.</li>
      <li>With a mission selected, new tasks go into that mission automatically.</li>
      <li>Understands dates like 10-Oct-26, 10/10/2026, today, tonight, tomorrow, weekday names, this/next weekend, and times like 6pm, 10:30am, at 18:00.</li>
      <li>Optional Claude AI sorting (when an API key is set); built-in rules otherwise. You always review before saving.</li>
    </ul>
    <h3>Tasks</h3>
    <ul>
      <li>Fields: description, date, time, status (New, Progress, Completed, Cancelled), progress (rich text), remark (rich text), mission, bandwidth, priority, effort, category, person.</li>
      <li>Open tasks are grouped as <b>Today</b> (including overdue), <b>This week</b> (next 7 days) and <b>Later</b> (including no date).</li>
      <li>Shows “in 25m” for tasks due soon today; overdue tasks are flagged.</li>
      <li>One tap to complete; separate Completed and Cancelled views; search across everything.</li>
    </ul>
    <h3>Missions</h3>
    <ul>
      <li>Always visible at the top left (a chip row on phones), with tasks done / total, a progress bar and days left to target.</li>
      <li>Tap a mission to see only its tasks, with its goal and progress on top.</li>
      <li>Colour, goal, target date and status (Active, Completed, Cancelled). Deleting a mission keeps its tasks.</li>
    </ul>
    <h3>Ideas</h3>
    <ul><li>Own view as cards, with rich-text note and tag; searchable; “Make it a task” in one tap.</li></ul>
    <h3>Bandwidths</h3>
    <ul>
      <li>Each user creates and edits their own: name, description, days, time window, hours per week. “Load defaults” restores the starter set.</li>
      <li>Load meter per bandwidth (open effort in the next 7 days vs. weekly hours) and a “Now” marker for the current slot. Shown at the bottom of the page.</li>
    </ul>
    <h3>App and security</h3>
    <ul>
      <li>Installable app (PWA) on phone and desktop; opens offline with your last loaded data.</li>
      <li>Sign-in per user; every record is private to its owner; CSRF protection; rich text cleaned on the server.</li>
      <li>DEV / LIVE marker so the test copy is never confused with production.</li>
    </ul>
  </section>

  <section>
    <h2>Release history</h2>
    <div class="rel"><span class="rv">1.1</span><span class="rd">29-Sep-2026 · renamed to rcexe</span>
      <ul>
        <li>New: <b>Missions</b> (short-term projects) with progress, target date and colour, pinned top left.</li>
        <li>New: <b>Ideas</b> — keep thoughts that are not tasks; turn them into tasks later.</li>
        <li>New: task <b>time</b>, rich-text <b>Progress</b> and <b>Remark</b>; statuses New / Progress / Completed / Cancelled.</li>
        <li>New: open tasks grouped as <b>Today / This week / Later</b>.</li>
        <li>New: this About page with goals, features and release history.</li>
        <li>Changed: denser layout — slim header, one-line add box, compact rows; bandwidths moved to the bottom.</li>
        <li>Changed: bandwidths clearly per user, with “Load defaults”.</li>
        <li>Upgrade: existing tasks kept; old notes became remarks; To do → New, In progress/Waiting → Progress, Done → Completed.</li>
      </ul>
    </div>
    <div class="rel"><span class="rv">1.0</span><span class="rd">27-Sep-2026 · as rctask</span>
      <ul>
        <li>Tasks from plain text (Claude or built-in rules) with a review step.</li>
        <li>Bandwidths with load meters and “Now”; sorting, search, rich-text notes.</li>
        <li>Sign-in, installable PWA with offline reading, DEV / LIVE marker, production config switch.</li>
      </ul>
    </div>
  </section>

  <section>
    <h2>Next</h2>
    <ul>
      <li>Reminders on phone and desktop at the task time.</li>
      <li>Events (what happened) and expenses with monthly totals.</li>
      <li>Recurring tasks.</li>
    </ul>
  </section>
</div>
</body>
</html>
