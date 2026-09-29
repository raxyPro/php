/* rcfamily (PHP edition) — front end. Talks to api.php. */
(function () {
  'use strict';

  /* ---------- helpers ---------- */
  const $ = (id) => document.getElementById(id);
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const todayISO = () => iso(new Date());
  const addDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return iso(d); };
  const fmt = (s) => { if (!s) return ''; const [y, m, d] = s.split('-'); return `${d}-${MON[+m - 1]}-${y.slice(2)}`; };
  const daysFrom = (s) => Math.round((new Date(s + 'T00:00') - new Date(todayISO() + 'T00:00')) / 86400000);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
  const hm = (m) => { m = Math.round(+m || 0); if (m < 60) return m + 'm'; const h = Math.floor(m / 60), r = m % 60; return r ? `${h}h ${r}m` : `${h}h`; };
  const ISO_RE = /^\d{4}-\d{2}-\d{2}$/;
  const newKey = () => Math.random().toString(36).slice(2, 10);
  let toastT;
  const toast = (m) => { const el = $('toast'); el.textContent = m; el.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => (el.hidden = true), 2600); };
  const lsGet = (k) => { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } };
  const lsSet = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* ignore */ } };

  /* ---------- API ---------- */
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  async function api(action, body) {
    const opts = body === undefined
      ? { method: 'GET', credentials: 'same-origin' }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) };
    const res = await fetch('api.php?a=' + encodeURIComponent(action), opts);
    if (res.status === 401) { location.href = 'login.php'; throw new Error('signed out'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  /* ---------- state ---------- */
  const HUES = ['blue', 'amber', 'violet', 'teal', 'rose', 'olive', 'slate', 'orange'];
  let bws = [];
  let tasks = new Map();
  let aiOn = false;
  let ready = false;
  const prefs = Object.assign({ sort: 'due', dir: 'asc', st: 'open', bw: '' }, lsGet('rcphp.prefs') || {});
  const savePrefs = () => lsSet('rcphp.prefs', prefs);
  const bwById = (id) => bws.find((b) => b.id === id);

  async function loadAll() {
    const d = await api('bootstrap');
    tasks = new Map(d.tasks.map((t) => [t.id, t]));
    bws = d.bandwidths;
    aiOn = !!d.ai;
    ready = true;
    updateAiNote();
    render();
  }
  async function refreshTasks() {
    try { const d = await api('tasks'); tasks = new Map(d.tasks.map((t) => [t.id, t])); render(); } catch (e) { /* offline: keep what we have */ }
  }
  async function putTask(t) {
    const d = await api('task_save', t);
    tasks.set(d.task.id, d.task);
    render();
    return d.task;
  }
  async function delTask(id) {
    await api('task_delete', { id });
    tasks.delete(id);
    render();
  }

  /* ---------- now ---------- */
  const toMin = (s) => { const [h, m] = s.split(':').map(Number); return h * 60 + (m || 0); };
  function nowBw() {
    const d = new Date(), day = d.getDay(), mins = d.getHours() * 60 + d.getMinutes();
    return bws.find((b) => {
      if (!b.days.length || !b.start || !b.end || !b.days.includes(day)) return false;
      const s = toMin(b.start), e = toMin(b.end);
      return s <= e ? mins >= s && mins < e : mins >= s || mins < e;
    });
  }

  /* ---------- built-in rule parser (used when Claude is off or fails) ---------- */
  const MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, sept: 8, oct: 9, nov: 10, dec: 11 };
  const PREP = 'before|by|on|due(?:\\s+on)?|until|till|latest\\s+by|this|next';
  function findDate(text) {
    const s = ` ${text} `, now = new Date();
    const yr = (v) => (!v ? now.getFullYear() : v.length === 2 ? 2000 + +v : +v);
    const ok = (y, mo, d) => { const dt = new Date(y, mo, d); return dt.getMonth() === mo ? iso(dt) : ''; };
    let m;
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+)?(\\d{1,2})(?:st|nd|rd|th)?[\\s\\-/.]*(jan|feb|mar|apr|may|jun|jul|aug|sept|sep|oct|nov|dec)[a-z]*\\.?(?:[\\s\\-/.,]+(\\d{4}|\\d{2}))?(?=[\\s,.;!?])', 'i').exec(s)))
      return { d: ok(yr(m[3]), MONTHS[m[2].toLowerCase()], +m[1]), m: m[0] };
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+)?(\\d{1,2})[/\\-.](\\d{1,2})[/\\-.](\\d{2,4})(?=[\\s,.;!?])', 'i').exec(s)))
      return { d: ok(yr(m[3]), +m[2] - 1, +m[1]), m: m[0] };
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+)?(today|tonight|tomorrow|tmrw)(?=[\\s,.;!?])', 'i').exec(s))) {
      const w = m[1].toLowerCase();
      return { d: addDays(w === 'tomorrow' || w === 'tmrw' ? 1 : 0), m: m[0] };
    }
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+)?(sunday|monday|tuesday|wednesday|thursday|friday|saturday)(?=[\\s,.;!?])', 'i').exec(s))) {
      const tgt = DAYS.map((x) => x.toLowerCase()).indexOf(m[1].toLowerCase());
      let diff = (tgt - now.getDay() + 7) % 7;
      if (/next/i.test(m[0]) && diff === 0) diff = 7;
      return { d: addDays(diff), m: m[0] };
    }
    if ((m = /\s(?:this\s+|on\s+(?:the\s+)?|next\s+)?weekend(?=[\s,.;!?])/i.exec(s))) {
      let diff = (6 - now.getDay() + 7) % 7;
      if (/next/i.test(m[0])) diff += 7;
      return { d: addDays(diff), m: m[0], weekend: true };
    }
    return { d: '', m: '' };
  }
  const BW_RULES = [
    ['weekend', /\b(weekend|saturday|sunday)\b/i],
    ['evening', /\b(tonight|this evening|after work)\b/i],
    ['work', /\b(office|client|meeting|report|deck|presentation|sprint|project|team|manager|steering|proposal|invoice|stakeholder|vendor|jira|standup|status update|sow|rfp)\b/i],
    ['errand', /^(call|pay|recharge|book|order|transfer|message|whatsapp|text|reply|remind|email)\b/i],
    ['morning', /\b(walk|run|gym|yoga|exercise|meditat\w*|read|workout|jog)\b/i],
    ['weekend', /\b(shopping|market|mall|servic\w*|pollution|puc|garage|visit|trip|outing|haircut|bank|dentist|doctor|follow-up)\b/i],
    ['evening', /\b(fix|repair|tap|hinge|cook|laundry|kids|homework|plan|organi[sz]e|clean|sort|file|home)\b/i],
  ];
  const guessBw = (t) => { for (const [id, re] of BW_RULES) if (re.test(t) && bwById(id)) return bwById(id); return bwById('evening') || bws[0]; };
  const CAT_RULES = [
    ['Work', /\b(office|client|meeting|report|deck|sprint|project|team|steering|proposal)\b/i],
    ['Vehicle', /\b(bike|car|scooter|pollution|puc|petrol|tyre|insurance)\b/i],
    ['Health', /\b(doctor|dental|dentist|hospital|medicine|checkup|walk|gym|yoga)\b/i],
    ['Finance', /\b(itr|tax|ca|bank|emi|loan|sip|invest\w*|bill)\b/i],
    ['Home', /\b(tap|hinge|door|balcony|clean|repair|plumber|electric\w*|house|kitchen)\b/i],
    ['Family', /\b(kids?|mom|dad|school|birthday|anniversary|baby)\b/i],
    ['Shopping', /\b(buy|order|shop\w*|grocer\w*)\b/i],
  ];
  function ruleParse(text) {
    const parts = text
      .split(/\n|;|\s*•\s*|,\s+(?:and\s+)?|\s+and\s+(?=(?:call|pay|fix|book|send|prepare|take|renew|buy|get|do|clean|order|file|submit|check|visit)\b)/i)
      .map((s) => s.trim()).filter((s) => s.length > 2);
    return parts.slice(0, 25).map((p) => {
      const dt = findDate(p);
      let t = ` ${p} `.replace(dt.m, ' ').replace(/\s+/g, ' ').trim();
      t = t.replace(/^(todo:?|to-do:?|task:?|remind me to|i need to|need to|have to|don'?t forget to|and)\s+/i, '').replace(/[\s,;:\-–]+$/, '').replace(/^do\s+/i, '');
      const bw = dt.weekend && bwById('weekend') ? bwById('weekend') : guessBw(p);
      const eff = { errand: 10, morning: 30, work: 60, evening: 45, weekend: 90 }[bw.id] || 30;
      let pri = 'Medium';
      if (/\b(urgent|asap|important|critical)\b/i.test(p) || (dt.d && daysFrom(dt.d) <= 3)) pri = 'High';
      else if (/\b(someday|sometime|if possible|optional)\b/i.test(p)) pri = 'Low';
      const cat = (CAT_RULES.find(([, re]) => re.test(p)) || ['Personal'])[0];
      return { key: newKey(), title: cap(t) || cap(p), bandwidth: bw.id, due: dt.d, priority: pri, effort: eff, category: cat, person: '', why: '' };
    });
  }
  function normalize(arr) {
    return (Array.isArray(arr) ? arr : []).filter((x) => x && typeof x === 'object' && String(x.title || '').trim()).slice(0, 25).map((x) => ({
      key: newKey(),
      title: cap(String(x.title).trim()).slice(0, 200),
      bandwidth: bwById(String(x.bandwidth)) ? String(x.bandwidth) : guessBw(String(x.title)).id,
      due: ISO_RE.test(String(x.due || '')) ? String(x.due) : '',
      priority: ['High', 'Medium', 'Low'].includes(x.priority) ? x.priority : 'Medium',
      effort: Math.min(600, Math.max(5, Math.round(+x.effort_min || 30))),
      category: String(x.category || 'Personal').slice(0, 40),
      person: x.person ? String(x.person).slice(0, 60) : '',
      why: x.why ? String(x.why).slice(0, 140) : '',
    }));
  }

  /* ---------- compose ---------- */
  let drafts = [], draftSource = '', draftBy = '', draftNote = '';
  function updateAiNote() {
    $('aiState').textContent = aiOn
      ? 'Claude splits it into tasks and picks a bandwidth, priority, due date and effort for each. You review before they are added.'
      : 'Tasks are sorted with built-in rules. Add your Claude API key in config.php to have Claude sort them.';
  }
  $('prompt').addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); createTasks(); } });
  $('goBtn').addEventListener('click', createTasks);
  document.querySelectorAll('[data-ex]').forEach((b) => b.addEventListener('click', () => { $('prompt').value = b.dataset.ex; $('prompt').focus(); }));

  async function createTasks() {
    const text = $('prompt').value.trim();
    if (!text) { $('prompt').focus(); return; }
    let list = null, by = 'rules', note = '';
    if (aiOn) {
      $('goBtn').disabled = true;
      $('aiState').innerHTML = '<span class="spin"></span>Claude is reading your note…';
      try {
        const d = await api('parse', { text });
        if (d.ok) { list = normalize(d.tasks); by = 'claude'; if (!list.length) { list = null; note = 'Claude found no tasks in that text, so built-in rules were used.'; } }
        else note = d.reason === 'not_configured' ? 'Claude is not set up, so built-in rules were used.' : 'Claude could not be reached, so built-in rules were used.';
      } catch (e) {
        note = 'Claude could not be reached, so built-in rules were used.';
      } finally {
        $('goBtn').disabled = false;
        updateAiNote();
      }
    }
    if (!list) list = ruleParse(text);
    if (!list.length) { toast('No tasks found in that text.'); return; }
    drafts = list; draftSource = text; draftBy = by; draftNote = note;
    renderDrafts();
  }
  const bwOptions = (sel) => bws.map((b) => `<option value="${esc(b.id)}"${b.id === sel ? ' selected' : ''}>${esc(b.name)}</option>`).join('');
  function renderDrafts() {
    const box = $('drafts');
    if (!drafts.length) { box.hidden = true; box.innerHTML = ''; return; }
    const n = drafts.length;
    box.hidden = false;
    box.innerHTML = `<div class="drafts-h"><h2>Review ${n} new task${n === 1 ? '' : 's'}</h2><span>${draftBy === 'claude' ? 'Sorted by Claude. Change anything before adding.' : esc(draftNote || 'Sorted by built-in rules. Change anything before adding.')}</span></div>` +
      drafts.map((d) => `<div class="draft" data-k="${d.key}">
        <div class="dt"><span class="dlbl">Task</span><input data-f="title" value="${esc(d.title)}" aria-label="Task"></div>
        <div><span class="dlbl">Bandwidth</span><select data-f="bandwidth" aria-label="Bandwidth">${bwOptions(d.bandwidth)}</select></div>
        <div><span class="dlbl">Priority</span><select data-f="priority" aria-label="Priority">${['High', 'Medium', 'Low'].map((p) => `<option${p === d.priority ? ' selected' : ''}>${p}</option>`).join('')}</select></div>
        <div><span class="dlbl">Due</span><input data-f="due" type="date" value="${esc(d.due)}" aria-label="Due date"></div>
        <div><span class="dlbl">Minutes</span><input data-f="effort" type="number" min="5" step="5" value="${d.effort}" aria-label="Effort in minutes"></div>
        <button type="button" class="x" data-rm="${d.key}" aria-label="Remove this task">×</button>
        ${d.why ? `<div class="why">${esc(d.why)}</div>` : ''}
      </div>`).join('') +
      `<div class="drafts-f"><button type="button" class="btn ghost" id="dDiscard">Discard</button><button type="button" class="btn primary" id="dAdd">Add ${n} task${n === 1 ? '' : 's'}</button></div>`;
    $('dDiscard').onclick = () => { drafts = []; renderDrafts(); };
    $('dAdd').onclick = addDrafts;
    box.querySelectorAll('[data-rm]').forEach((b) => (b.onclick = () => { drafts = drafts.filter((d) => d.key !== b.dataset.rm); renderDrafts(); }));
    box.querySelectorAll('[data-f]').forEach((inp) => inp.addEventListener('input', () => {
      const d = drafts.find((x) => x.key === inp.closest('.draft').dataset.k);
      if (!d) return;
      const f = inp.dataset.f;
      d[f] = f === 'effort' ? Math.max(5, +inp.value || 5) : inp.value;
    }));
  }
  async function addDrafts() {
    const btn = $('dAdd');
    btn.disabled = true;
    const list = drafts.filter((d) => String(d.title).trim());
    try {
      for (const d of list) {
        await putTask({ title: d.title, bandwidth: d.bandwidth, due: d.due, priority: d.priority, effort: d.effort, category: d.category, person: d.person, status: 'To do', notes: '', why: d.why, source: draftSource });
      }
      drafts = []; renderDrafts(); $('prompt').value = '';
      toast(`Added ${list.length} task${list.length === 1 ? '' : 's'}`);
    } catch (e) {
      btn.disabled = false;
      toast(e.message || 'Could not save.');
    }
  }

  /* ---------- bandwidth cards ---------- */
  function renderBws() {
    const nb = nowBw();
    const open = [...tasks.values()].filter((t) => t.status !== 'Done');
    $('bws').innerHTML = bws.map((b) => {
      const mine = open.filter((t) => t.bandwidth === b.id);
      const week = mine.filter((t) => t.due && daysFrom(t.due) <= 7).reduce((a, t) => a + (+t.effort || 0), 0);
      const capMin = (+b.hoursPerWeek || 0) * 60;
      const pct = capMin ? Math.min(100, Math.round((week / capMin) * 100)) : 0;
      const over = capMin && week > capMin;
      const back = mine.reduce((a, t) => a + (+t.effort || 0), 0);
      return `<button type="button" class="bw c-${esc(b.hue)}" data-bw="${esc(b.id)}" aria-pressed="${prefs.bw === b.id}">
        <span class="n"><span>${esc(b.name)}</span>${nb && b.id === nb.id ? '<span class="now">Now</span>' : ''}</span>
        <span class="w">${esc(b.when)}</span>
        <span class="cnt">${mine.length} open · ${hm(back)} total</span>
        <span class="meter${over ? ' over' : ''}" aria-hidden="true"><i style="width:${pct}%"></i></span>
        <span class="ld${over ? ' over' : ''}">Next 7 days: ${hm(week)} of ${hm(capMin)}${over ? ' · over' : ''}</span>
      </button>`;
    }).join('');
    $('bws').querySelectorAll('[data-bw]').forEach((el) => (el.onclick = () => { prefs.bw = prefs.bw === el.dataset.bw ? '' : el.dataset.bw; savePrefs(); render(); }));
    $('nowPill').innerHTML = `Right now: <b>${nb ? esc(nb.name) : 'off hours'}</b>`;
  }

  /* ---------- list ---------- */
  const PRI = { High: 0, Medium: 1, Low: 2 };
  const ST = { 'In progress': 0, 'To do': 1, Waiting: 2, Done: 3 };
  function cmp(a, b, key) {
    switch (key) {
      case 'due': return a.due === b.due ? 0 : a.due < b.due ? -1 : 1;
      case 'priority': return (PRI[a.priority] ?? 1) - (PRI[b.priority] ?? 1);
      case 'bandwidth': { const i = (id) => { const x = bws.findIndex((q) => q.id === id); return x < 0 ? 999 : x; }; return i(a.bandwidth) - i(b.bandwidth); }
      case 'effort': return (+a.effort || 0) - (+b.effort || 0);
      case 'title': return String(a.title).localeCompare(String(b.title));
      case 'status': return (ST[a.status] ?? 1) - (ST[b.status] ?? 1);
      case 'category': return String(a.category || '').localeCompare(String(b.category || ''));
      case 'created': return String(a.createdAt || '').localeCompare(String(b.createdAt || ''));
    }
    return 0;
  }
  function sorted(list) {
    const sign = prefs.dir === 'desc' ? -1 : 1;
    return list.slice().sort((a, b) => {
      if (prefs.sort === 'due' && (!a.due || !b.due)) { if (!a.due && !b.due) return cmp(a, b, 'priority'); return a.due ? -1 : 1; }
      const r = cmp(a, b, prefs.sort) * sign;
      if (r) return r;
      if (a.due !== b.due) return !a.due ? 1 : !b.due ? -1 : cmp(a, b, 'due');
      return cmp(a, b, 'priority');
    });
  }
  function dueCell(t) {
    if (!t.due) return '<div class="due"><span class="muted">—</span></div>';
    const n = daysFrom(t.due);
    let lab = '', cls = '';
    if (t.status !== 'Done') {
      if (n < 0) { lab = `${-n}d overdue`; cls = 'over'; } else if (n === 0) { lab = 'Today'; cls = 'soon'; } else if (n === 1) { lab = 'Tomorrow'; cls = 'soon'; } else if (n <= 7) lab = `In ${n} days`;
    }
    return `<div class="due ${cls}"><span>${fmt(t.due)}</span>${lab ? `<small>${lab}</small>` : ''}</div>`;
  }
  const CHECK = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path d="M3 8.5l3.2 3L13 4.5"/></svg>';
  const hdr = (key, label, cls) => { const act = prefs.sort === key; return `<button type="button" data-sort="${key}" data-active="${act}" class="${cls || ''}">${label}${act ? (prefs.dir === 'asc' ? ' ↑' : ' ↓') : ''}</button>`; };

  function render() {
    if (!ready) return;
    renderBws();
    $('sortSel').value = prefs.sort;
    $('dirBtn').textContent = prefs.dir === 'asc' ? '↑' : '↓';
    document.querySelectorAll('[data-st]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.st === prefs.st)));
    const ft = $('filterTag');
    const sel = bwById(prefs.bw);
    if (sel) {
      ft.hidden = false;
      ft.innerHTML = `Showing <b>${esc(sel.name)}</b> <button type="button" id="clrBw">Show all</button>`;
      $('clrBw').onclick = () => { prefs.bw = ''; savePrefs(); render(); };
    } else { ft.hidden = true; prefs.bw = ''; }

    const q = $('q').value.trim().toLowerCase();
    let list = [...tasks.values()];
    if (prefs.st === 'open') list = list.filter((t) => t.status !== 'Done');
    else if (prefs.st === 'done') list = list.filter((t) => t.status === 'Done');
    if (sel) list = list.filter((t) => t.bandwidth === sel.id);
    if (q) list = list.filter((t) => [t.title, t.category, t.person, t.source, (t.notes || '').replace(/<[^>]+>/g, ' '), (bwById(t.bandwidth) || {}).name].join(' ').toLowerCase().includes(q));
    list = sorted(list);

    const head = `<div class="tr th"><span></span>${hdr('title', 'Task')}${hdr('bandwidth', 'Bandwidth')}${hdr('priority', 'Priority')}${hdr('due', 'Due')}${hdr('effort', 'Effort', 'num')}</div>`;
    const rows = list.map((t) => {
      const b = bwById(t.bandwidth) || { name: 'Unassigned', hue: 'slate' };
      const done = t.status === 'Done';
      const st = !done && t.status !== 'To do' ? `<span class="st ${t.status === 'In progress' ? 'prog' : 'wait'}">${esc(t.status)}</span>` : '';
      const meta = [st, t.category ? esc(t.category) : '', t.person ? esc(t.person) : ''].filter(Boolean).map((x) => `<span>${x}</span>`).join('');
      return `<div class="tr${done ? ' done' : ''}">
        <button type="button" class="chk" data-done="${esc(t.id)}" aria-label="${done ? 'Mark as not done' : 'Mark as done'}: ${esc(t.title)}">${CHECK}</button>
        <button type="button" class="cell-t" data-open="${esc(t.id)}"><span class="tt">${esc(t.title)}</span>${meta ? `<span class="tm">${meta}</span>` : ''}</button>
        <div class="bwc"><span class="bwchip c-${esc(b.hue)}">${esc(b.name)}</span><span class="pri pri-m ${esc(t.priority)}"><i></i>${esc(t.priority)}</span></div>
        <div class="pric"><span class="pri ${esc(t.priority)}"><i></i>${esc(t.priority)}</span></div>
        ${dueCell(t)}
        <div class="eff">${hm(t.effort)}</div>
      </div>`;
    }).join('');
    const emptyMsg = tasks.size ? 'No tasks match this view.' : 'No tasks yet. Describe what needs doing in the box above.';
    $('tbl').innerHTML = head + (rows || `<div class="empty">${emptyMsg}</div>`);
  }

  $('tbl').addEventListener('click', async (e) => {
    const s = e.target.closest('[data-sort]');
    if (s) {
      const k = s.dataset.sort;
      if (prefs.sort === k) prefs.dir = prefs.dir === 'asc' ? 'desc' : 'asc';
      else { prefs.sort = k; prefs.dir = k === 'effort' || k === 'created' ? 'desc' : 'asc'; }
      savePrefs(); render(); return;
    }
    const d = e.target.closest('[data-done]');
    if (d) {
      const t = tasks.get(d.dataset.done);
      if (!t) return;
      const next = Object.assign({}, t, { status: t.status === 'Done' ? 'To do' : 'Done' });
      try { await putTask(next); toast(next.status === 'Done' ? 'Marked done' : 'Moved back to open'); } catch (err) { toast(err.message); }
      return;
    }
    const o = e.target.closest('[data-open]');
    if (o) openEditor(o.dataset.open);
  });
  $('sortSel').addEventListener('change', () => { prefs.sort = $('sortSel').value; savePrefs(); render(); });
  $('dirBtn').addEventListener('click', () => { prefs.dir = prefs.dir === 'asc' ? 'desc' : 'asc'; savePrefs(); render(); });
  document.querySelectorAll('[data-st]').forEach((b) => b.addEventListener('click', () => { prefs.st = b.dataset.st; savePrefs(); render(); }));
  $('q').addEventListener('input', render);

  /* ---------- editor ---------- */
  let editing = null;
  function openEditor(id) {
    const t = tasks.get(id);
    if (!t) return;
    editing = Object.assign({}, t);
    const src = t.source ? ` from “${esc(t.source.length > 140 ? t.source.slice(0, 140) + '…' : t.source)}”` : '';
    $('edSrc').innerHTML = (t.createdAt ? `Added ${fmt(t.createdAt.slice(0, 10))}` : '') + src + (t.why ? `<br>Why this bandwidth: ${esc(t.why)}` : '');
    $('eTitle').value = t.title || '';
    $('eBw').innerHTML = bwOptions(t.bandwidth) + (bwById(t.bandwidth) ? '' : `<option value="${esc(t.bandwidth)}" selected>Unassigned</option>`);
    $('eStatus').value = t.status || 'To do';
    $('ePri').value = t.priority || 'Medium';
    $('eEff').value = t.effort || 30;
    $('eDue').value = t.due || '';
    $('eCat').value = t.category || '';
    $('ePerson').value = t.person || '';
    $('notes').innerHTML = t.notes || '';
    $('catList').innerHTML = ['Work', 'Home', 'Family', 'Health', 'Finance', 'Vehicle', 'Shopping', 'Personal', 'Admin'].map((c) => `<option value="${c}">`).join('');
    const del = $('eDel'); del.classList.remove('armed'); del.textContent = 'Delete';
    bwHelp();
    $('edScrim').hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(() => $('eTitle').focus(), 30);
  }
  function bwHelp() { const b = bwById($('eBw').value); $('eBwHelp').textContent = b ? b.when : ''; }
  $('eBw').addEventListener('change', bwHelp);
  $('eDueClear').addEventListener('click', () => { $('eDue').value = ''; });
  function closeEditor() { $('edScrim').hidden = true; document.body.style.overflow = ''; editing = null; }
  $('eCancel').addEventListener('click', closeEditor);
  $('edScrim').addEventListener('click', (e) => { if (e.target === $('edScrim')) closeEditor(); });
  $('edForm').addEventListener('submit', (e) => { e.preventDefault(); saveEditor(); });
  $('eSave').addEventListener('click', saveEditor);
  $('eDel').addEventListener('click', async () => {
    const b = $('eDel');
    if (!b.classList.contains('armed')) { b.classList.add('armed'); b.textContent = 'Tap again to delete'; return; }
    const id = editing.id;
    closeEditor();
    try { await delTask(id); toast('Task deleted'); } catch (err) { toast(err.message); }
  });
  document.querySelectorAll('#tools button').forEach((b) => {
    b.addEventListener('mousedown', (e) => e.preventDefault());
    b.addEventListener('click', () => {
      $('notes').focus();
      const c = b.dataset.cmd;
      if (c === 'h3') { const cur = String(document.queryCommandValue('formatBlock')).toLowerCase(); document.execCommand('formatBlock', false, cur === 'h3' ? 'P' : 'H3'); }
      else document.execCommand(c, false, null);
    });
  });
  $('notes').addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain')); });
  async function saveEditor() {
    const title = $('eTitle').value.trim();
    if (!title) { $('eTitle').focus(); return; }
    const notes = $('notes').textContent.trim() ? $('notes').innerHTML : '';
    const t = Object.assign({}, editing, {
      title, bandwidth: $('eBw').value, status: $('eStatus').value, priority: $('ePri').value,
      effort: Math.max(5, +$('eEff').value || 30), due: $('eDue').value, category: $('eCat').value.trim(), person: $('ePerson').value.trim(), notes,
    });
    $('eSave').disabled = true;
    try { await putTask(t); closeEditor(); toast('Saved'); } catch (err) { toast(err.message); }
    $('eSave').disabled = false;
  }

  /* ---------- bandwidth settings ---------- */
  const DAY_L = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
  let bwDraft = [];
  $('openSettings').addEventListener('click', () => {
    bwDraft = bws.map((b) => Object.assign({}, b, { days: [...b.days] }));
    renderBwSettings();
    $('bwScrim').hidden = false;
    document.body.style.overflow = 'hidden';
  });
  function closeBw() { $('bwScrim').hidden = true; document.body.style.overflow = ''; }
  $('bwCancel').addEventListener('click', closeBw);
  $('bwScrim').addEventListener('click', (e) => { if (e.target === $('bwScrim')) closeBw(); });
  function renderBwSettings() {
    const used = (id) => [...tasks.values()].some((t) => t.bandwidth === id);
    $('bwBody').innerHTML = bwDraft.map((b, i) => `<div class="bwedit c-${esc(b.hue)}" data-i="${i}">
      <div class="bwedit-top"><span class="sw"></span><input data-f="name" value="${esc(b.name)}" aria-label="Bandwidth name">
        <button type="button" class="x" data-rmbw="${i}" ${used(b.id) ? 'disabled title="Move its tasks to another bandwidth first"' : ''} aria-label="Remove ${esc(b.name)}">×</button></div>
      <input data-f="when" value="${esc(b.when)}" aria-label="When it happens and what fits">
      <div class="bwedit-row">
        <div class="days" role="group" aria-label="Days">${DAY_L.map((d, di) => `<button type="button" data-day="${di}" aria-label="${DAYS[di]}" aria-pressed="${b.days.includes(di)}">${d}</button>`).join('')}</div>
        <input data-f="start" type="time" value="${esc(b.start)}" aria-label="Start time"><span class="muted">to</span><input data-f="end" type="time" value="${esc(b.end)}" aria-label="End time">
        <label class="hrs"><input data-f="hoursPerWeek" type="number" min="0" step="0.5" value="${esc(b.hoursPerWeek)}" aria-label="Hours per week"> h/week</label>
      </div>
    </div>`).join('') + '<p class="help">Leave days empty for a slot with no fixed time, like “On the go”. A bandwidth that still has tasks cannot be removed.</p>';
    $('bwBody').querySelectorAll('[data-f]').forEach((inp) => inp.addEventListener('input', () => {
      const b = bwDraft[+inp.closest('.bwedit').dataset.i];
      b[inp.dataset.f] = inp.dataset.f === 'hoursPerWeek' ? Math.max(0, +inp.value || 0) : inp.value;
    }));
    $('bwBody').querySelectorAll('[data-day]').forEach((btn) => (btn.onclick = () => {
      const b = bwDraft[+btn.closest('.bwedit').dataset.i], d = +btn.dataset.day;
      b.days = b.days.includes(d) ? b.days.filter((x) => x !== d) : [...b.days, d].sort();
      btn.setAttribute('aria-pressed', String(b.days.includes(d)));
    }));
    $('bwBody').querySelectorAll('[data-rmbw]').forEach((x) => (x.onclick = () => { bwDraft.splice(+x.dataset.rmbw, 1); renderBwSettings(); }));
  }
  $('bwAdd').addEventListener('click', () => {
    const usedH = new Set(bwDraft.map((b) => b.hue));
    bwDraft.push({ id: 'bw' + newKey().slice(0, 6), name: 'New bandwidth', when: 'When is it, and what kind of task fits?', hoursPerWeek: 2, hue: HUES.find((h) => !usedH.has(h)) || HUES[bwDraft.length % HUES.length], days: [], start: '', end: '' });
    renderBwSettings();
    const ins = $('bwBody').querySelectorAll('input[data-f="name"]');
    ins[ins.length - 1].select();
  });
  $('bwSave').addEventListener('click', async () => {
    const list = bwDraft.filter((b) => String(b.name).trim());
    if (!list.length) { toast('Keep at least one bandwidth.'); return; }
    try { const d = await api('bandwidths_save', { list }); bws = d.bandwidths; closeBw(); render(); toast('Bandwidths saved'); }
    catch (err) { toast(err.message); }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { if (!$('edScrim').hidden) closeEditor(); else if (!$('bwScrim').hidden) closeBw(); } });

  // pick up changes made on another device when you come back to the tab
  document.addEventListener('visibilitychange', () => { if (!document.hidden && ready) refreshTasks(); });
  setInterval(() => ready && renderBws(), 60000);

  loadAll().catch((e) => { $('tbl').innerHTML = `<div class="empty">Could not load your tasks. ${esc(e.message)}</div>`; });
})();
