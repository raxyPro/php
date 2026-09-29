<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$uid = require_login_page();
$st = db()->prepare('SELECT name, email FROM users WHERE id = ?');
$st->execute([$uid]);
$me = $st->fetch() ?: ['name' => '', 'email' => ''];
$V = APP_VERSION;
/** Rich-text editor block. */
function rte(string $id, string $label, string $ph): string
{
    $tools = '<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>'
        . '<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>'
        . '<button type="button" data-cmd="underline" title="Underline"><u>U</u></button>'
        . '<button type="button" data-cmd="h3" title="Heading">H</button>'
        . '<button type="button" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>'
        . '<button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>'
        . '<button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>';
    return '<div class="f"><span class="lbl" id="' . $id . 'Lbl">' . h($label) . '</span><div class="rte"><div class="rte-tools">' . $tools
        . '</div><div id="' . $id . '" class="rte-body" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="' . $id . 'Lbl" data-ph="' . h($ph) . '"></div></div></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <meta name="theme-color" content="<?= env_theme_color() ?>">
  <title><?= h(env_title_prefix()) ?><?= APP_NAME ?></title>
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="<?= APP_NAME ?>">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="assets/app.css?v=<?= $V ?>">
</head>
<body class="env-<?= app_env() ?>">
<?= env_banner() ?>
<div class="wrap">
  <header class="top">
    <div class="brand">
      <h1><?= APP_NAME ?><?= env_badge() ?></h1>
      <span class="ver">v<?= $V ?> · rc execute</span>
    </div>
    <div class="top-r">
      <span class="nowpill" id="nowPill"></span>
      <button class="btn small primary" id="installBtn" type="button" hidden>Install app</button>
      <a class="btn small ghost" href="about.php">About</a>
      <a class="btn small ghost" href="logout.php" title="<?= h($me['email']) ?>">Sign out</a>
    </div>
  </header>

  <div class="layout">
    <!-- missions: top left -->
    <aside class="side" aria-label="Missions">
      <div class="side-h">
        <h2>Missions</h2>
        <button class="btn small" id="newMission" type="button">+ New</button>
      </div>
      <nav class="mlist" id="mlist"></nav>
    </aside>

    <main class="main">
      <section class="compose" id="compose" aria-label="Add">
        <textarea id="prompt" rows="1" aria-label="What needs doing?" placeholder="What needs doing? e.g. File ITR: collect Form 16, call CA by Friday · idea: …"></textarea>
        <div class="c-more">
          <div class="c-row">
            <span class="grow" id="aiState"></span>
            <button class="btn ghost small" id="cCancel" type="button">Close</button>
            <button class="btn primary" id="goBtn" type="button">Create</button>
          </div>
          <div class="examples">Try:
            <button type="button" data-ex="Visit doctor by this Tuesday at 6pm">Visit doctor by Tuesday at 6pm</button>
            <button type="button" data-ex="File ITR: collect Form 16 from HR, call CA about deductions, verify 26AS before 31-Jul-27">File ITR: Form 16, call CA, verify 26AS</button>
            <button type="button" data-ex="Mission Buy EV: shortlist 3 models, book test drives this weekend, compare loan offers">Mission Buy EV…</button>
            <button type="button" data-ex="idea: difference between gold and silver - no one remembers who came second">idea: gold vs silver</button>
          </div>
        </div>
      </section>

      <section class="drafts" id="drafts" hidden aria-label="Review before adding"></section>

      <div id="mhead"></div>

      <div class="ctrl">
        <div class="seg" role="group" aria-label="View">
          <button type="button" data-view="open" aria-pressed="true">Open <span class="n" id="nOpen"></span></button>
          <button type="button" data-view="Completed" aria-pressed="false">Completed <span class="n" id="nDone"></span></button>
          <button type="button" data-view="Cancelled" aria-pressed="false">Cancelled</button>
          <button type="button" data-view="ideas" aria-pressed="false">Ideas <span class="n" id="nIdeas"></span></button>
        </div>
        <input id="q" type="search" placeholder="Search" aria-label="Search">
      </div>

      <div id="list"><div class="empty"><span class="spin"></span>Loading…</div></div>

      <!-- bandwidths: last -->
      <section class="bw-sec" aria-label="Bandwidths">
        <div class="bw-h">
          <h2>Bandwidths</h2>
          <span class="muted small">Open effort due in 7 days vs. hours per week. Tap a card to filter.</span>
          <button class="btn small" id="openSettings" type="button">Edit my bandwidths</button>
        </div>
        <div class="bws" id="bws"></div>
      </section>
    </main>
  </div>
</div>

<!-- task editor -->
<div class="scrim" id="edScrim" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="edTitle">
    <div class="sh-head"><h3 id="edTitle">Task</h3><div class="src" id="edSrc"></div></div>
    <form class="sh-body" id="edForm" novalidate>
      <div class="f"><label for="eTitle">Description</label><input id="eTitle" type="text"></div>
      <div class="g2">
        <div class="f"><label for="eMission">Mission</label><select id="eMission"></select></div>
        <div class="f"><label for="eStatus">Status</label><select id="eStatus"><option>New</option><option>Progress</option><option>Completed</option><option>Cancelled</option></select></div>
      </div>
      <div class="g2">
        <div class="f"><label for="eDue">Date</label><div class="datebox"><input id="eDue" type="date"><button type="button" id="eDueClear">None</button></div></div>
        <div class="f"><label for="eTime">Time</label><div class="datebox"><input id="eTime" type="time"><button type="button" id="eTimeClear">None</button></div></div>
      </div>
      <?= rte('eProgress', 'Progress', 'What has been done so far…') ?>
      <?= rte('eRemark', 'Remark', 'Links, who to call, anything to remember…') ?>
      <details class="more">
        <summary>More: bandwidth, priority, effort, category, person</summary>
        <div class="g2">
          <div class="f"><label for="eBw">Bandwidth</label><select id="eBw"></select><div class="help" id="eBwHelp"></div></div>
          <div class="f"><label for="ePri">Priority</label><select id="ePri"><option>High</option><option>Medium</option><option>Low</option></select></div>
        </div>
        <div class="g2">
          <div class="f"><label for="eEff">Effort (minutes)</label><input id="eEff" type="number" min="5" step="5" inputmode="numeric"></div>
          <div class="f"><label for="eCat">Category</label><input id="eCat" type="text" list="catList"><datalist id="catList"></datalist></div>
        </div>
        <div class="f"><label for="ePerson">Person</label><input id="ePerson" type="text" placeholder="Who is involved?"></div>
      </details>
    </form>
    <div class="sh-foot">
      <button class="btn danger" id="eDel" type="button">Delete</button>
      <span class="sp"></span>
      <button class="btn ghost" id="eCancel" type="button">Cancel</button>
      <button class="btn primary" id="eSave" type="button">Save</button>
    </div>
  </div>
</div>

<!-- mission editor -->
<div class="scrim" id="mScrim" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="mTitleH">
    <div class="sh-head"><h3 id="mTitleH">Mission</h3><div class="src">A short-term goal made of several tasks, e.g. “File ITR” or “Buy EV”.</div></div>
    <form class="sh-body" id="mForm" novalidate>
      <div class="f"><label for="mTitle">Mission</label><input id="mTitle" type="text" placeholder="e.g. Buy EV"></div>
      <div class="f"><label for="mGoal">Goal / definition of done</label><textarea id="mGoal" rows="3" placeholder="What does done look like?"></textarea></div>
      <div class="g2">
        <div class="f"><label for="mTarget">Target date</label><div class="datebox"><input id="mTarget" type="date"><button type="button" id="mTargetClear">None</button></div></div>
        <div class="f"><label for="mStatus">Status</label><select id="mStatus"><option>Active</option><option>Completed</option><option>Cancelled</option></select></div>
      </div>
      <div class="f"><span class="lbl">Colour</span><div class="hues" id="mHues"></div></div>
      <p class="help" id="mHelp"></p>
    </form>
    <div class="sh-foot">
      <button class="btn danger" id="mDel" type="button">Delete</button>
      <span class="sp"></span>
      <button class="btn ghost" id="mCancel" type="button">Cancel</button>
      <button class="btn primary" id="mSave" type="button">Save</button>
    </div>
  </div>
</div>

<!-- idea editor -->
<div class="scrim" id="iScrim" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="iTitleH">
    <div class="sh-head"><h3 id="iTitleH">Idea</h3><div class="src" id="iSrc"></div></div>
    <form class="sh-body" id="iForm" novalidate>
      <div class="f"><label for="iTitle">Idea</label><input id="iTitle" type="text"></div>
      <?= rte('iNotes', 'Note', 'Why it matters, where you heard it…') ?>
      <div class="f"><label for="iCat">Tag</label><input id="iCat" type="text" list="catList" placeholder="e.g. Learning"></div>
    </form>
    <div class="sh-foot">
      <button class="btn danger" id="iDel" type="button">Delete</button>
      <button class="btn ghost small" id="iToTask" type="button">Make it a task</button>
      <span class="sp"></span>
      <button class="btn ghost" id="iCancel" type="button">Cancel</button>
      <button class="btn primary" id="iSave" type="button">Save</button>
    </div>
  </div>
</div>

<!-- bandwidth settings -->
<div class="scrim" id="bwScrim" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="bwTitle">
    <div class="sh-head"><h3 id="bwTitle">My bandwidths</h3><div class="src">Your own slots of the week — each user has their own. Name each slot, when it happens and how many hours a week you can give it. The description helps place new tasks; days and times decide which slot shows as “Now”.</div></div>
    <div class="sh-body" id="bwBody"></div>
    <div class="sh-foot">
      <button class="btn ghost" id="bwAdd" type="button">Add bandwidth</button>
      <button class="btn ghost small" id="bwReset" type="button">Load defaults</button>
      <span class="sp"></span>
      <button class="btn ghost" id="bwCancel" type="button">Cancel</button>
      <button class="btn primary" id="bwSave" type="button">Save</button>
    </div>
  </div>
</div>
<div class="toast" id="toast" hidden></div>
<script>window.RC_DEFAULT_BW = <?= json_encode(Bandwidths::defaults(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js?v=<?= $V ?>"></script>
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
  window.addEventListener('offline', function () { note('You are offline. You can view but not save changes.'); });
  window.addEventListener('online', function () { note('Back online'); });
})();
</script>
</body>
</html>
