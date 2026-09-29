<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$uid = require_login_page();
$st = db()->prepare('SELECT name, email FROM users WHERE id = ?');
$st->execute([$uid]);
$me = $st->fetch() ?: ['name' => '', 'email' => ''];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <meta name="theme-color" content="<?= env_theme_color() ?>">
  <title><?= h(env_title_prefix()) ?>rcfamily</title>
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="rctask">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body class="env-<?= app_env() ?>">
<?= env_banner() ?>
<div class="wrap">
  <header class="top">
    <div>
      <h1>rcfamily<?= env_badge() ?></h1>
      <div class="sub">Tasks sorted by when in your week you can actually do them</div>
    </div>
    <div class="top-r">
      <span class="nowpill" id="nowPill"></span>
      <button class="btn small primary" id="installBtn" type="button" hidden>Install app</button>
      <button class="btn small" id="openSettings" type="button">Edit bandwidths</button>
      <a class="btn small ghost" href="logout.php" title="<?= h($me['email']) ?>">Sign out</a>
    </div>
  </header>

  <section class="compose" aria-label="Add tasks">
    <label for="prompt">Describe what needs doing. One line or several.</label>
    <textarea id="prompt" placeholder="e.g. do bike pollution check before 10-Oct-26, fix the leaking bathroom tap, and send the steering committee deck to the client by Tuesday"></textarea>
    <div class="c-row">
      <span class="grow" id="aiState"></span>
      <button class="btn primary" id="goBtn" type="button">Create tasks</button>
    </div>
    <div class="examples">Try:
      <button type="button" data-ex="Renew car insurance before 5-Oct-26, call CA about ITR filing, and take Rita for dental follow-up on Saturday">Renew car insurance before 5-Oct-26, call CA about ITR…</button>
      <button type="button" data-ex="Prepare sprint 6 status deck for steering committee by Tuesday
Fix the loose balcony door hinge
30 min walk every morning this week">Sprint deck by Tuesday, fix balcony hinge…</button>
    </div>
  </section>

  <section class="drafts" id="drafts" hidden aria-label="Review new tasks"></section>

  <section class="bw-sec" aria-label="Bandwidths">
    <div class="bw-h"><h2>Your bandwidths</h2><span>Load = effort of open tasks due in the next 7 days vs. weekly hours. Click a card to filter.</span></div>
    <div class="bws" id="bws"></div>
  </section>

  <div class="ctrl">
    <div class="l">
      <div class="seg" role="group" aria-label="Status">
        <button type="button" data-st="open" aria-pressed="true">Open</button>
        <button type="button" data-st="done" aria-pressed="false">Done</button>
        <button type="button" data-st="all" aria-pressed="false">All</button>
      </div>
      <span class="filtertag" id="filterTag" hidden></span>
    </div>
    <div class="r">
      <input id="q" type="search" placeholder="Search tasks" aria-label="Search tasks">
      <div class="sortbox">
        <label for="sortSel">Sort</label>
        <select id="sortSel">
          <option value="due">Due date</option><option value="priority">Priority</option><option value="bandwidth">Bandwidth</option>
          <option value="effort">Effort</option><option value="title">Title</option><option value="status">Status</option>
          <option value="category">Category</option><option value="created">Date added</option>
        </select>
        <button type="button" id="dirBtn" aria-label="Reverse sort order">↑</button>
      </div>
    </div>
  </div>

  <div class="tbl" id="tbl"><div class="empty"><span class="spin"></span>Loading your tasks…</div></div>
</div>

<!-- task editor -->
<div class="scrim" id="edScrim" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="edTitle">
    <div class="sh-head"><h3 id="edTitle">Edit task</h3><div class="src" id="edSrc"></div></div>
    <form class="sh-body" id="edForm" novalidate>
      <div class="f"><label for="eTitle">Task</label><input id="eTitle" type="text"></div>
      <div class="g2">
        <div class="f"><label for="eBw">Bandwidth</label><select id="eBw"></select><div class="help" id="eBwHelp"></div></div>
        <div class="f"><label for="eStatus">Status</label><select id="eStatus"><option>To do</option><option>In progress</option><option>Waiting</option><option>Done</option></select></div>
      </div>
      <div class="g2">
        <div class="f"><label for="ePri">Priority</label><select id="ePri"><option>High</option><option>Medium</option><option>Low</option></select></div>
        <div class="f"><label for="eEff">Effort (minutes)</label><input id="eEff" type="number" min="5" step="5" inputmode="numeric"></div>
      </div>
      <div class="f"><label for="eDue">Due date</label><div class="datebox"><input id="eDue" type="date"><button type="button" id="eDueClear">No date</button></div></div>
      <div class="g2">
        <div class="f"><label for="eCat">Category</label><input id="eCat" type="text" list="catList"><datalist id="catList"></datalist></div>
        <div class="f"><label for="ePerson">Person</label><input id="ePerson" type="text" placeholder="Who is involved?"></div>
      </div>
      <div class="f">
        <span class="lbl" id="notesLbl">Notes</span>
        <div class="rte">
          <div class="rte-tools" id="tools">
            <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
            <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
            <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
            <button type="button" data-cmd="h3" title="Heading">H</button>
            <button type="button" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>
            <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
            <button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>
          </div>
          <div id="notes" class="rte-body" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="notesLbl" data-ph="Steps, links, who to call…"></div>
        </div>
      </div>
    </form>
    <div class="sh-foot">
      <button class="btn danger" id="eDel" type="button">Delete</button>
      <span class="sp"></span>
      <button class="btn ghost" id="eCancel" type="button">Cancel</button>
      <button class="btn primary" id="eSave" type="button">Save</button>
    </div>
  </div>
</div>

<!-- bandwidth settings -->
<div class="scrim" id="bwScrim" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="bwTitle">
    <div class="sh-head"><h3 id="bwTitle">Your bandwidths</h3><div class="src">Name each slot of your week, when it happens and how many hours a week you can give it. Claude reads the description to place new tasks; the days and times decide which slot shows as “Now”.</div></div>
    <div class="sh-body" id="bwBody"></div>
    <div class="sh-foot">
      <button class="btn ghost" id="bwAdd" type="button">Add bandwidth</button>
      <span class="sp"></span>
      <button class="btn ghost" id="bwCancel" type="button">Cancel</button>
      <button class="btn primary" id="bwSave" type="button">Save</button>
    </div>
  </div>
</div>
<div class="toast" id="toast" hidden></div>
<script src="assets/app.js?v=2"></script>
<script>
/* PWA: service worker, install button, offline notice */
(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function (e) { console.warn('SW registration failed', e); });
    });
  }
  var installBtn = document.getElementById('installBtn'), deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; installBtn.hidden = false; });
  installBtn.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.finally(function () { deferred = null; installBtn.hidden = true; });
  });
  window.addEventListener('appinstalled', function () { installBtn.hidden = true; });
  function note(msg) { var t = document.getElementById('toast'); t.textContent = msg; t.hidden = false; setTimeout(function () { t.hidden = true; }, 3000); }
  window.addEventListener('offline', function () { note('You are offline. You can view tasks but not save changes.'); });
  window.addEventListener('online', function () { note('Back online'); });
  if (location.hash === '#add') { var p = document.getElementById('prompt'); if (p) p.focus(); }
})();
</script>
</body>
</html>
