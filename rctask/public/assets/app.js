/* rcexe (rc execute) — front end. Talks to api.php. */
(function () {
  'use strict';

  /* ---------- helpers ---------- */
  const $ = (id) => document.getElementById(id);
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const DSH = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const todayISO = () => iso(new Date());
  const addDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return iso(d); };
  const fmt = (s) => { if (!s) return ''; const [y, m, d] = s.split('-'); return `${d}-${MON[+m - 1]}-${y.slice(2)}`; };
  const daysFrom = (s) => Math.round((new Date(s + 'T00:00') - new Date(todayISO() + 'T00:00')) / 86400000);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
  const hm = (m) => { m = Math.round(+m || 0); if (m < 60) return m + 'm'; const h = Math.floor(m / 60), r = m % 60; return r ? `${h}h ${r}m` : `${h}h`; };
  const strip = (h) => String(h || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
  const ISO_RE = /^\d{4}-\d{2}-\d{2}$/;
  const TIME_RE = /^\d{2}:\d{2}$/;
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
  const CATS = ['Work', 'Home', 'Family', 'Health', 'Finance', 'Vehicle', 'Shopping', 'Personal', 'Admin', 'Learning'];
  const OPEN = ['New', 'Progress'];
  let bws = [];
  let entries = new Map();   // tasks + ideas
  let missions = new Map();
  let aiOn = false, ready = false;
  const prefs = Object.assign({ view: 'open', mission: '', bw: '', showClosedM: false }, lsGet('rcexe.prefs') || {});
  const savePrefs = () => lsSet('rcexe.prefs', prefs);
  const bwById = (id) => bws.find((b) => b.id === id);
  const tasks = () => [...entries.values()].filter((e) => e.kind === 'task');
  const ideas = () => [...entries.values()].filter((e) => e.kind === 'idea');
  const missionList = () => [...missions.values()];
  const activeMissions = () => missionList().filter((m) => m.status === 'Active');
  function mStats(id) {
    const ts = tasks().filter((t) => t.mission === id && t.status !== 'Cancelled');
    const done = ts.filter((t) => t.status === 'Completed').length;
    return { total: ts.length, done, pct: ts.length ? Math.round((done / ts.length) * 100) : 0 };
  }

  async function loadAll() {
    const d = await api('bootstrap');
    entries = new Map(d.entries.map((t) => [t.id, t]));
    missions = new Map(d.missions.map((m) => [m.id, m]));
    bws = d.bandwidths;
    aiOn = !!d.ai;
    ready = true;
    if (prefs.mission && !missions.has(prefs.mission)) prefs.mission = '';
    updateAiNote();
    render();
  }
  async function refresh() {
    try {
      const d = await api('entries');
      entries = new Map(d.entries.map((t) => [t.id, t]));
      missions = new Map(d.missions.map((m) => [m.id, m]));
      render();
    } catch (e) { /* offline: keep what we have */ }
  }
  async function putEntry(t) {
    const d = await api('entry_save', t);
    entries.set(d.entry.id, d.entry);
    render();
    return d.entry;
  }
  async function delEntry(id) {
    await api('entry_delete', { id });
    entries.delete(id);
    render();
  }
  async function putMission(m) {
    const d = await api('mission_save', m);
    missions.set(d.mission.id, d.mission);
    render();
    return d.mission;
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
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+){0,2}(\\d{1,2})(?:st|nd|rd|th)?[\\s\\-/.]*(jan|feb|mar|apr|may|jun|jul|aug|sept|sep|oct|nov|dec)[a-z]*\\.?(?:[\\s\\-/.,]+(\\d{4}|\\d{2}))?(?=[\\s,.;!?])', 'i').exec(s)))
      return { d: ok(yr(m[3]), MONTHS[m[2].toLowerCase()], +m[1]), m: m[0] };
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+){0,2}(\\d{1,2})[/\\-.](\\d{1,2})[/\\-.](\\d{2,4})(?=[\\s,.;!?])', 'i').exec(s)))
      return { d: ok(yr(m[3]), +m[2] - 1, +m[1]), m: m[0] };
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+){0,2}(today|tonight|tomorrow|tmrw)(?=[\\s,.;!?])', 'i').exec(s))) {
      const w = m[1].toLowerCase();
      return { d: addDays(w === 'tomorrow' || w === 'tmrw' ? 1 : 0), m: m[0], evening: w === 'tonight' };
    }
    if ((m = new RegExp('\\s(?:(?:' + PREP + ')\\s+){0,2}(sunday|monday|tuesday|wednesday|thursday|friday|saturday)(?=[\\s,.;!?])', 'i').exec(s))) {
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
  function findTime(text) {
    const s = ` ${text} `;
    let m;
    if ((m = /\s(?:at\s+|@\s*)?(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)(?=[\s,.;!?])/i.exec(s))) {
      let h = +m[1] % 12; if (m[3].toLowerCase() === 'pm') h += 12;
      const mi = m[2] ? +m[2] : 0;
      if (h < 24 && mi < 60) return { t: `${pad(h)}:${pad(mi)}`, m: m[0] };
    }
    if ((m = /\s(?:at\s+|@\s*)(\d{1,2})[:.](\d{2})(?=[\s,.;!?])/i.exec(s)) && +m[1] < 24 && +m[2] < 60) return { t: `${pad(+m[1])}:${m[2]}`, m: m[0] };
    return { t: '', m: '' };
  }
  const BW_RULES = [
    ['weekend', /\b(weekend|saturday|sunday)\b/i],
    ['evening', /\b(tonight|this evening|after work)\b/i],
    ['work', /\b(office|client|meeting|report|deck|presentation|sprint|project|team|manager|steering|proposal|invoice|stakeholder|vendor|jira|standup|status update|sow|rfp|hr)\b/i],
    ['errand', /^(call|pay|recharge|book|order|transfer|message|whatsapp|text|reply|remind|email|verify|check)\b/i],
    ['morning', /\b(walk|run|gym|yoga|exercise|meditat\w*|read|workout|jog)\b/i],
    ['weekend', /\b(shopping|market|mall|servic\w*|pollution|puc|garage|visit|trip|outing|haircut|bank|dentist|doctor|follow-up|test drive|library)\b/i],
    ['evening', /\b(fix|repair|tap|hinge|cook|laundry|kids|homework|plan|organi[sz]e|clean|sort|file|home|shortlist|compare)\b/i],
  ];
  const guessBw = (t) => { for (const [id, re] of BW_RULES) if (re.test(t) && bwById(id)) return bwById(id); return bwById('evening') || bws[0] || { id: '' }; };
  const CAT_RULES = [
    ['Work', /\b(office|client|meeting|report|deck|sprint|project|team|steering|proposal)\b/i],
    ['Vehicle', /\b(bike|car|scooter|ev|pollution|puc|petrol|tyre|insurance|test drive)\b/i],
    ['Health', /\b(doctor|dental|dentist|hospital|medicine|checkup|walk|gym|yoga)\b/i],
    ['Finance', /\b(itr|tax|ca|bank|emi|loan|sip|invest\w*|bill|form 16|26as)\b/i],
    ['Home', /\b(tap|hinge|door|balcony|clean|repair|plumber|electric\w*|house|kitchen)\b/i],
    ['Learning', /\b(book|read|library|course|trainer|training|learn\w*|skill)\b/i],
    ['Family', /\b(kids?|mom|dad|school|birthday|anniversary|baby)\b/i],
    ['Shopping', /\b(buy|order|shop\w*|grocer\w*)\b/i],
  ];
  function ruleTask(p, missionRef, source) {
    const dt = findDate(p), tm = findTime(p);
    let t = ` ${p} `.replace(dt.m, ' ').replace(tm.m, ' ').replace(/\s+/g, ' ').trim();
    t = t.replace(/^(todo:?|to-do:?|task:?|remind me to|i need to|need to|have to|don'?t forget to|and)\s+/i, '').replace(/[\s,;:\-–]+$/, '').replace(/^do\s+/i, '');
    // a stated day + time picks the user's own bandwidth covering that moment
    let bw = null;
    if (dt.d && tm.t) {
      const day = new Date(dt.d + 'T00:00').getDay(), at = toMin(tm.t);
      bw = bws.find((b) => b.days.includes(day) && b.start && b.end && at >= toMin(b.start) && at < toMin(b.end)) || null;
    }
    if (!bw) bw = dt.weekend && bwById('weekend') ? bwById('weekend') : dt.evening && bwById('evening') ? bwById('evening') : guessBw(p);
    const eff = { errand: 10, morning: 30, work: 60, evening: 45, weekend: 90 }[bw.id] || 30;
    let pri = 'Medium';
    if (/\b(urgent|asap|important|critical)\b/i.test(p) || (dt.d && daysFrom(dt.d) <= 3)) pri = 'High';
    else if (/\b(someday|sometime|if possible|optional)\b/i.test(p)) pri = 'Low';
    const cat = (CAT_RULES.find(([, re]) => re.test(p)) || ['Personal'])[0];
    return { key: newKey(), title: cap(t) || cap(p), mission: missionRef, bandwidth: bw.id, due: dt.d, time: tm.t, priority: pri, effort: eff, category: cat, person: '', why: '', source };
  }
  const splitTasks = (s) => s
    .split(/;|\s*•\s*|,\s+(?:and\s+)?|\s+and\s+(?=(?:call|pay|fix|book|send|prepare|take|renew|buy|get|do|clean|order|file|submit|check|visit|verify|collect|compare|shortlist|find)\b)/i)
    .map((x) => x.trim()).filter((x) => x.length > 2);
  const findMissionByTitle = (title) => { const k = title.trim().toLowerCase(); return missionList().find((m) => m.title.toLowerCase() === k); };

  function ruleParse(text) {
    const out = { missions: [], tasks: [], ideas: [] };
    const defRef = prefs.mission && missions.has(prefs.mission) ? 'm:' + prefs.mission : '';
    for (const raw of text.split(/\n+/)) {
      const line = raw.trim();
      if (!line) continue;
      let m;
      if ((m = /^idea\s*[:\-–]\s*(.+)$/i.exec(line))) {
        out.ideas.push({ key: newKey(), title: cap(m[1].trim()).slice(0, 300), note: '', category: '' });
        continue;
      }
      // "Mission X: a, b, c" or "X: a, b, c" (several steps) -> a mission with tasks
      if ((m = /^(?:mission\s+)?([^:]{2,60}):\s*(.+)$/i.exec(line)) && (/^mission\s/i.test(line) || splitTasks(m[2]).length > 1)) {
        const mt = cap(m[1].replace(/^mission\s+/i, '').trim());
        const ex = findMissionByTitle(mt);
        let ref;
        if (ex) ref = 'm:' + ex.id;
        else {
          let dm = out.missions.find((x) => x.title.toLowerCase() === mt.toLowerCase());
          if (!dm) { dm = { key: newKey(), title: mt, goal: '', target: '' }; out.missions.push(dm); }
          ref = 'n:' + dm.key;
        }
        const parts = splitTasks(m[2]);
        // a date on the last step ("before 31-Jul") often means the whole mission
        const tasksHere = parts.map((p) => ruleTask(p, ref, line));
        const lastDate = findDate(parts[parts.length - 1] || '').d;
        const dm = ref.startsWith('n:') ? out.missions.find((x) => 'n:' + x.key === ref) : null;
        if (dm && lastDate && !dm.target) dm.target = lastDate;
        out.tasks.push(...tasksHere);
        continue;
      }
      for (const p of splitTasks(line)) out.tasks.push(ruleTask(p, defRef, line));
    }
    return out;
  }

  /* Claude output -> same shape as ruleParse */
  function normalize(d) {
    const out = { missions: [], tasks: [], ideas: [] };
    const byTitle = new Map();
    for (const x of (Array.isArray(d.missions) ? d.missions : []).slice(0, 10)) {
      const title = cap(String(x && x.title || '').trim()).slice(0, 200);
      if (!title) continue;
      const ex = findMissionByTitle(title);
      if (ex) { byTitle.set(title.toLowerCase(), 'm:' + ex.id); continue; }
      const dm = { key: newKey(), title, goal: String(x.goal || '').slice(0, 1000), target: ISO_RE.test(String(x.target || '')) ? String(x.target) : '' };
      out.missions.push(dm);
      byTitle.set(title.toLowerCase(), 'n:' + dm.key);
    }
    const defRef = prefs.mission && missions.has(prefs.mission) ? 'm:' + prefs.mission : '';
    for (const x of (Array.isArray(d.tasks) ? d.tasks : []).slice(0, 30)) {
      if (!x || !String(x.title || '').trim()) continue;
      let ref = defRef;
      const mv = String(x.mission || '');
      if (mv && missions.has(mv)) ref = 'm:' + mv;
      else if (mv && byTitle.has(mv.toLowerCase())) ref = byTitle.get(mv.toLowerCase());
      else if (mv) { const ex = findMissionByTitle(mv); if (ex) ref = 'm:' + ex.id; }
      out.tasks.push({
        key: newKey(),
        title: cap(String(x.title).trim()).slice(0, 200),
        mission: ref,
        bandwidth: bwById(String(x.bandwidth)) ? String(x.bandwidth) : guessBw(String(x.title)).id,
        due: ISO_RE.test(String(x.due || '')) ? String(x.due) : '',
        time: TIME_RE.test(String(x.time || '')) ? String(x.time) : '',
        priority: ['High', 'Medium', 'Low'].includes(x.priority) ? x.priority : 'Medium',
        effort: Math.min(600, Math.max(5, Math.round(+x.effort_min || 30))),
        category: String(x.category || 'Personal').slice(0, 40),
        person: x.person ? String(x.person).slice(0, 60) : '',
        why: x.why ? String(x.why).slice(0, 140) : '',
      });
    }
    for (const x of (Array.isArray(d.ideas) ? d.ideas : []).slice(0, 20)) {
      if (!x || !String(x.title || '').trim()) continue;
      out.ideas.push({ key: newKey(), title: cap(String(x.title).trim()).slice(0, 300), note: String(x.note || '').slice(0, 2000), category: '' });
    }
    return out;
  }

  /* ---------- compose ---------- */
  let draft = null, draftSource = '', draftBy = '', draftNote = '';
  function updateAiNote() {
    $('aiState').textContent = aiOn
      ? 'Claude splits your note into missions, tasks and ideas. You review before anything is saved.'
      : 'Built-in rules: “X: a, b, c” makes mission X with 3 tasks; “idea: …” saves an idea. Ctrl+Enter to create.';
  }
  const comp = $('compose'), prompt = $('prompt');
  const autosize = () => { prompt.style.height = 'auto'; prompt.style.height = Math.min(prompt.scrollHeight, 260) + 'px'; };
  prompt.addEventListener('focus', () => comp.classList.add('open'));
  prompt.addEventListener('input', autosize);
  prompt.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); createFromText(); } });
  $('cCancel').addEventListener('click', () => { comp.classList.remove('open'); prompt.blur(); });
  $('goBtn').addEventListener('click', createFromText);
  document.querySelectorAll('[data-ex]').forEach((b) => b.addEventListener('click', () => { prompt.value = b.dataset.ex; autosize(); prompt.focus(); }));

  async function createFromText() {
    const text = prompt.value.trim();
    if (!text) { prompt.focus(); return; }
    let out = null, by = 'rules', note = '';
    if (aiOn) {
      $('goBtn').disabled = true;
      $('aiState').innerHTML = '<span class="spin"></span>Claude is reading your note…';
      try {
        const d = await api('parse', { text });
        if (d.ok) {
          out = normalize(d); by = 'claude';
          if (!out.tasks.length && !out.ideas.length && !out.missions.length) { out = null; note = 'Claude found nothing, so built-in rules were used.'; }
        } else note = d.reason === 'not_configured' ? 'Claude is not set up, so built-in rules were used.' : 'Claude could not be reached, so built-in rules were used.';
      } catch (e) {
        note = 'Claude could not be reached, so built-in rules were used.';
      } finally {
        $('goBtn').disabled = false;
        updateAiNote();
      }
    }
    if (!out) out = ruleParse(text);
    if (!out.tasks.length && !out.ideas.length && !out.missions.length) { toast('Nothing found in that text.'); return; }
    draft = out; draftSource = text; draftBy = by; draftNote = note;
    renderDrafts();
  }

  const bwOptions = (sel) => bws.map((b) => `<option value="${esc(b.id)}"${b.id === sel ? ' selected' : ''}>${esc(b.name)}</option>`).join('');
  function missionOptions(sel, withDraft) {
    let h = `<option value="">— No mission —</option>`;
    if (withDraft) h += draft.missions.map((m) => `<option value="n:${m.key}"${sel === 'n:' + m.key ? ' selected' : ''}>★ ${esc(m.title || 'New mission')} (new)</option>`).join('');
    const list = activeMissions();
    const cur = sel && sel.startsWith('m:') ? missions.get(sel.slice(2)) : null;
    if (cur && !list.includes(cur)) list.push(cur);
    h += list.map((m) => `<option value="m:${esc(m.id)}"${sel === 'm:' + m.id ? ' selected' : ''}>${esc(m.title)}</option>`).join('');
    return h;
  }

  function renderDrafts() {
    const box = $('drafts');
    if (!draft || (!draft.tasks.length && !draft.ideas.length && !draft.missions.length)) { box.hidden = true; box.innerHTML = ''; draft = null; return; }
    const nT = draft.tasks.length, nI = draft.ideas.length, nM = draft.missions.length;
    const parts = [nM && `${nM} mission${nM > 1 ? 's' : ''}`, nT && `${nT} task${nT > 1 ? 's' : ''}`, nI && `${nI} idea${nI > 1 ? 's' : ''}`].filter(Boolean).join(', ');
    box.hidden = false;
    let h = `<div class="drafts-h"><h2>Review: ${parts}</h2><span>${draftBy === 'claude' ? 'Sorted by Claude.' : esc(draftNote || 'Sorted by built-in rules.')} Change anything before adding.</span></div>`;
    if (nM) {
      h += `<div class="dgrp">New missions</div>` + draft.missions.map((m) => `<div class="draft dm" data-k="${m.key}" data-t="m">
        <div class="dt"><span class="dlbl">Mission</span><input data-f="title" value="${esc(m.title)}" aria-label="Mission name"></div>
        <div><span class="dlbl">Target</span><input data-f="target" type="date" value="${esc(m.target)}" aria-label="Target date"></div>
        <button type="button" class="x" data-rm="m:${m.key}" aria-label="Remove mission">×</button>
      </div>`).join('');
    }
    if (nT) {
      h += `<div class="dgrp">Tasks</div>` + draft.tasks.map((d) => `<div class="draft dtk" data-k="${d.key}" data-t="t">
        <div class="dt"><span class="dlbl">Task</span><input data-f="title" value="${esc(d.title)}" aria-label="Task"></div>
        <div><span class="dlbl">Mission</span><select data-f="mission" aria-label="Mission">${missionOptions(d.mission, true)}</select></div>
        <div><span class="dlbl">Date</span><input data-f="due" type="date" value="${esc(d.due)}" aria-label="Date"></div>
        <div><span class="dlbl">Time</span><input data-f="time" type="time" value="${esc(d.time)}" aria-label="Time"></div>
        <div><span class="dlbl">Bandwidth</span><select data-f="bandwidth" aria-label="Bandwidth">${bwOptions(d.bandwidth)}</select></div>
        <button type="button" class="x" data-rm="t:${d.key}" aria-label="Remove task">×</button>
        ${d.why ? `<div class="why">${esc(d.why)}</div>` : ''}
      </div>`).join('');
    }
    if (nI) {
      h += `<div class="dgrp">Ideas</div>` + draft.ideas.map((d) => `<div class="draft di" data-k="${d.key}" data-t="i">
        <div class="dt"><span class="dlbl">Idea</span><input data-f="title" value="${esc(d.title)}" aria-label="Idea"></div>
        <button type="button" class="x" data-rm="i:${d.key}" aria-label="Remove idea">×</button>
        ${d.note ? `<div class="why">${esc(d.note)}</div>` : ''}
      </div>`).join('');
    }
    h += `<div class="drafts-f"><button type="button" class="btn ghost" id="dDiscard">Discard</button><button type="button" class="btn primary" id="dAdd">Add ${parts}</button></div>`;
    box.innerHTML = h;
    $('dDiscard').onclick = () => { draft = null; renderDrafts(); };
    $('dAdd').onclick = addDrafts;
    box.querySelectorAll('[data-rm]').forEach((b) => (b.onclick = () => {
      const [t, k] = b.dataset.rm.split(':');
      if (t === 'm') { draft.missions = draft.missions.filter((x) => x.key !== k); draft.tasks.forEach((x) => { if (x.mission === 'n:' + k) x.mission = ''; }); }
      if (t === 't') draft.tasks = draft.tasks.filter((x) => x.key !== k);
      if (t === 'i') draft.ideas = draft.ideas.filter((x) => x.key !== k);
      renderDrafts();
    }));
    box.querySelectorAll('[data-f]').forEach((inp) => inp.addEventListener('input', () => {
      const row = inp.closest('.draft');
      const list = { m: draft.missions, t: draft.tasks, i: draft.ideas }[row.dataset.t];
      const d = list.find((x) => x.key === row.dataset.k);
      if (d) d[inp.dataset.f] = inp.value;
      if (row.dataset.t === 'm' && inp.dataset.f === 'title') box.querySelectorAll('select[data-f="mission"]').forEach((s) => { const o = s.querySelector(`option[value="n:${row.dataset.k}"]`); if (o) o.textContent = `★ ${inp.value || 'New mission'} (new)`; });
    }));
  }

  async function addDrafts() {
    const btn = $('dAdd');
    btn.disabled = true;
    try {
      const keyToId = {};
      for (const m of draft.missions) {
        if (!String(m.title).trim()) continue;
        const saved = await putMission({ title: m.title, goal: m.goal, target: m.target, status: 'Active', hue: HUES[missions.size % HUES.length] });
        keyToId[m.key] = saved.id;
      }
      const ref = (r) => (!r ? '' : r.startsWith('m:') ? r.slice(2) : keyToId[r.slice(2)] || '');
      let n = 0;
      for (const d of draft.tasks) {
        if (!String(d.title).trim()) continue;
        await putEntry({ kind: 'task', title: d.title, mission: ref(d.mission), bandwidth: d.bandwidth, due: d.due, time: d.time, priority: d.priority, effort: d.effort, category: d.category, person: d.person, status: 'New', why: d.why, source: draftSource });
        n++;
      }
      for (const d of draft.ideas) {
        if (!String(d.title).trim()) continue;
        await putEntry({ kind: 'idea', title: d.title, notes: d.note ? `<p>${esc(d.note)}</p>` : '', category: d.category || '', status: 'New', source: draftSource });
        n++;
      }
      const madeIdeasOnly = !draft.tasks.length && draft.ideas.length;
      draft = null; renderDrafts(); prompt.value = ''; autosize(); comp.classList.remove('open');
      if (madeIdeasOnly) { prefs.view = 'ideas'; savePrefs(); render(); }
      toast(`Added ${n} item${n === 1 ? '' : 's'}`);
    } catch (e) {
      btn.disabled = false;
      toast(e.message || 'Could not save.');
    }
  }

  /* ---------- missions sidebar (top left) ---------- */
  function targetLabel(m) {
    if (!m.target || m.status !== 'Active') return m.target ? fmt(m.target) : '';
    const n = daysFrom(m.target);
    return n < 0 ? `${-n}d late` : n === 0 ? 'due today' : n <= 30 ? `${n}d left` : fmt(m.target);
  }
  function renderMissions() {
    const openAll = tasks().filter((t) => OPEN.includes(t.status)).length;
    const act = activeMissions(), closed = missionList().filter((m) => m.status !== 'Active');
    const item = (m) => {
      const s = mStats(m.id), tl = targetLabel(m);
      const late = m.status === 'Active' && m.target && daysFrom(m.target) < 0;
      return `<button type="button" class="mi c-${esc(m.hue)}${m.status !== 'Active' ? ' closed' : ''}" data-m="${esc(m.id)}" aria-pressed="${prefs.mission === m.id}">
        <span class="mi-t">${esc(m.title)}</span>
        <span class="mi-s"><span>${s.done}/${s.total}</span>${tl ? `<span class="${late ? 'late' : ''}">${esc(tl)}</span>` : ''}</span>
        <span class="meter" aria-hidden="true"><i style="width:${s.pct}%"></i></span>
      </button>`;
    };
    let h = `<button type="button" class="mi all" data-m="" aria-pressed="${!prefs.mission}"><span class="mi-t">All tasks</span><span class="mi-s"><span>${openAll} open</span></span></button>`;
    h += act.map(item).join('');
    if (!act.length) h += `<p class="mi-empty">No missions yet. A mission is a short goal like “File ITR” or “Buy EV”.</p>`;
    if (closed.length) {
      h += `<button type="button" class="mi-tog" id="mTog" aria-expanded="${prefs.showClosedM}">${prefs.showClosedM ? '▾' : '▸'} Completed &amp; cancelled (${closed.length})</button>`;
      if (prefs.showClosedM) h += closed.map(item).join('');
    }
    $('mlist').innerHTML = h;
    $('mlist').querySelectorAll('[data-m]').forEach((b) => (b.onclick = () => {
      prefs.mission = prefs.mission === b.dataset.m ? '' : b.dataset.m;
      if (prefs.view === 'ideas') prefs.view = 'open';
      savePrefs(); render();
    }));
    if ($('mTog')) $('mTog').onclick = () => { prefs.showClosedM = !prefs.showClosedM; savePrefs(); renderMissions(); };
  }
  function renderMissionHead() {
    const m = missions.get(prefs.mission);
    if (!m) { $('mhead').innerHTML = ''; return; }
    const s = mStats(m.id), tl = targetLabel(m);
    $('mhead').innerHTML = `<div class="mhead c-${esc(m.hue)}">
      <div class="mh-l">
        <div class="mh-k">Mission${m.status !== 'Active' ? ' · ' + esc(m.status) : ''}</div>
        <h2>${esc(m.title)}</h2>
        ${m.goal ? `<p>${esc(m.goal)}</p>` : ''}
        <div class="mh-s"><span>${s.done} of ${s.total} tasks done</span>${m.target ? `<span>Target: ${esc(fmt(m.target))}${m.status === 'Active' && tl && tl !== fmt(m.target) ? ' (' + esc(tl) + ')' : ''}</span>` : ''}</div>
        <span class="meter" aria-hidden="true"><i style="width:${s.pct}%"></i></span>
      </div>
      <div class="mh-r">
        <button type="button" class="btn small" id="mhAdd">+ Task</button>
        <button type="button" class="btn small ghost" id="mhEdit">Edit</button>
        <button type="button" class="btn small ghost" id="mhClose" aria-label="Show all tasks">✕</button>
      </div>
    </div>`;
    $('mhEdit').onclick = () => openMission(m.id);
    $('mhClose').onclick = () => { prefs.mission = ''; savePrefs(); render(); };
    $('mhAdd').onclick = () => { prompt.focus(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
  }

  /* ---------- list ---------- */
  const PRI = { High: 0, Medium: 1, Low: 2 };
  const byDue = (a, b) => {
    if (a.due !== b.due) return !a.due ? 1 : !b.due ? -1 : a.due < b.due ? -1 : 1;
    if (a.time !== b.time) return !a.time ? 1 : !b.time ? -1 : a.time < b.time ? -1 : 1;
    return (PRI[a.priority] ?? 1) - (PRI[b.priority] ?? 1);
  };
  function dueCell(t) {
    const open = OPEN.includes(t.status);
    if (!t.due) return `<div class="due"><span class="muted">${t.time ? esc(t.time) : '—'}</span></div>`;
    const n = daysFrom(t.due);
    let top = fmt(t.due), lab = '', cls = '';
    if (open) {
      if (n < 0) { lab = `${-n}d overdue`; cls = 'over'; }
      else if (n === 0) { top = 'Today'; cls = 'soon'; }
      else if (n === 1) { top = 'Tomorrow'; }
      else if (n <= 6) { top = DSH[new Date(t.due + 'T00:00').getDay()] + ' ' + t.due.slice(8); }
      if (n === 0 && t.time) {
        const mins = toMin(t.time) - (new Date().getHours() * 60 + new Date().getMinutes());
        if (mins >= 0 && mins <= 90) { lab = mins < 1 ? 'now' : `in ${hm(mins)}`; cls = 'soon'; }
        else if (mins < 0) { lab = 'passed'; cls = 'over'; }
      }
    }
    return `<div class="due ${cls}"><span>${esc(top)}${t.time ? ' <b>' + esc(t.time) + '</b>' : ''}</span>${lab ? `<small>${esc(lab)}</small>` : ''}</div>`;
  }
  const CHECK = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path d="M3 8.5l3.2 3L13 4.5"/></svg>';
  function row(t) {
    const b = bwById(t.bandwidth);
    const m = missions.get(t.mission);
    const done = t.status === 'Completed', cancelled = t.status === 'Cancelled';
    const tags = [
      m && !prefs.mission ? `<span class="mchip c-${esc(m.hue)}">${esc(m.title)}</span>` : '',
      t.status === 'Progress' ? '<span class="st prog">In progress</span>' : '',
      cancelled ? '<span class="st canc">Cancelled</span>' : '',
      b ? `<span class="bwdot c-${esc(b.hue)}">${esc(b.name)}</span>` : '',
      t.priority === 'High' ? '<span class="st hi">High</span>' : '',
      t.person ? `<span>${esc(t.person)}</span>` : '',
      strip(t.progress) ? `<span class="has" title="${esc(strip(t.progress).slice(0, 160))}">✎ progress</span>` : '',
    ].filter(Boolean).join('');
    return `<div class="tr${done ? ' done' : ''}${cancelled ? ' canc' : ''}">
      <button type="button" class="chk" data-done="${esc(t.id)}" aria-label="${done ? 'Mark as not done' : 'Mark as completed'}: ${esc(t.title)}">${CHECK}</button>
      <button type="button" class="cell-t" data-open="${esc(t.id)}"><span class="tt">${esc(t.title)}</span>${tags ? `<span class="tm">${tags}</span>` : ''}</button>
      ${dueCell(t)}
    </div>`;
  }
  function filtered() {
    const q = $('q').value.trim().toLowerCase();
    let list = tasks();
    if (prefs.mission) list = list.filter((t) => t.mission === prefs.mission);
    if (bwById(prefs.bw)) list = list.filter((t) => t.bandwidth === prefs.bw);
    if (q) list = list.filter((t) => [t.title, t.category, t.person, t.source, strip(t.progress), strip(t.remark), (bwById(t.bandwidth) || {}).name, (missions.get(t.mission) || {}).title].join(' ').toLowerCase().includes(q));
    return list;
  }
  function renderList() {
    const box = $('list');
    const bwSel = bwById(prefs.bw);
    const tag = bwSel ? `<div class="filtertag">Bandwidth: <b>${esc(bwSel.name)}</b> <button type="button" id="clrBw">Show all</button></div>` : '';

    if (prefs.view === 'ideas') {
      const q = $('q').value.trim().toLowerCase();
      const list = ideas().filter((i) => !q || [i.title, strip(i.notes), i.category].join(' ').toLowerCase().includes(q))
        .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
      box.innerHTML = list.length
        ? `<div class="ideas">${list.map((i) => `<button type="button" class="idea" data-idea="${esc(i.id)}">
            <span class="it">${esc(i.title)}</span>
            ${strip(i.notes) ? `<span class="in">${esc(strip(i.notes).slice(0, 220))}</span>` : ''}
            <span class="im">${i.category ? `<span class="itag">${esc(i.category)}</span>` : ''}<span>${esc(fmt(String(i.createdAt).slice(0, 10)))}</span></span>
          </button>`).join('')}</div>`
        : `<div class="empty">No ideas yet. Type <b>idea:</b> followed by a thought, e.g. “idea: difference between gold and silver — no one remembers who came second”.</div>`;
      return;
    }

    let list = filtered();
    if (prefs.view === 'open') {
      list = list.filter((t) => OPEN.includes(t.status)).sort(byDue);
      const g = { today: [], week: [], later: [], nodate: [] };
      for (const t of list) {
        if (!t.due) g.nodate.push(t);
        else { const n = daysFrom(t.due); (n <= 0 ? g.today : n <= 7 ? g.week : g.later).push(t); }
      }
      const sec = (id, title, sub, arr) => arr.length ? `<section class="grp g-${id}"><h3>${title} <span class="n">${arr.length}</span>${sub ? `<small>${sub}</small>` : ''}</h3><div class="tbl">${arr.map(row).join('')}</div></section>` : '';
      const overdue = g.today.filter((t) => daysFrom(t.due) < 0).length;
      const html = sec('today', 'Today', overdue ? `${overdue} overdue` : fmt(todayISO()), g.today)
        + sec('week', 'This week', 'next 7 days', g.week)
        + sec('later', 'Later', '', g.later.concat(g.nodate));
      const emptyMsg = tasks().length ? 'Nothing open in this view.' : 'Nothing yet. Type what needs doing in the box above.';
      box.innerHTML = tag + (html || `<div class="empty">${emptyMsg}</div>`);
    } else {
      list = list.filter((t) => t.status === prefs.view).sort((a, b) => String(b.doneAt || b.updatedAt).localeCompare(String(a.doneAt || a.updatedAt)));
      box.innerHTML = tag + (list.length ? `<div class="tbl">${list.map(row).join('')}</div>` : `<div class="empty">No ${prefs.view.toLowerCase()} tasks here.</div>`);
    }
    if ($('clrBw')) $('clrBw').onclick = () => { prefs.bw = ''; savePrefs(); render(); };
  }

  /* ---------- bandwidth cards (bottom) ---------- */
  function renderBws() {
    const nb = nowBw();
    const open = tasks().filter((t) => OPEN.includes(t.status));
    $('bws').innerHTML = bws.map((b) => {
      const mine = open.filter((t) => t.bandwidth === b.id);
      const week = mine.filter((t) => t.due && daysFrom(t.due) <= 7).reduce((a, t) => a + (+t.effort || 0), 0);
      const capMin = (+b.hoursPerWeek || 0) * 60;
      const pct = capMin ? Math.min(100, Math.round((week / capMin) * 100)) : 0;
      const over = capMin && week > capMin;
      return `<button type="button" class="bw c-${esc(b.hue)}" data-bw="${esc(b.id)}" aria-pressed="${prefs.bw === b.id}" title="${esc(b.when)}">
        <span class="n"><span>${esc(b.name)}</span>${nb && b.id === nb.id ? '<span class="now">Now</span>' : ''}</span>
        <span class="cnt">${mine.length} open · ${hm(week)} / ${hm(capMin)}</span>
        <span class="meter${over ? ' over' : ''}" aria-hidden="true"><i style="width:${pct}%"></i></span>
      </button>`;
    }).join('');
    $('bws').querySelectorAll('[data-bw]').forEach((el) => (el.onclick = () => {
      prefs.bw = prefs.bw === el.dataset.bw ? '' : el.dataset.bw;
      if (prefs.view === 'ideas') prefs.view = 'open';
      savePrefs(); render(); $('list').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    $('nowPill').innerHTML = `Now: <b>${nb ? esc(nb.name) : 'off hours'}</b>`;
  }

  function render() {
    if (!ready) return;
    const all = tasks();
    $('nOpen').textContent = all.filter((t) => OPEN.includes(t.status)).length || '';
    $('nDone').textContent = all.filter((t) => t.status === 'Completed').length || '';
    $('nIdeas').textContent = ideas().length || '';
    document.querySelectorAll('[data-view]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.view === prefs.view)));
    renderMissions();
    renderMissionHead();
    renderList();
    renderBws();
  }

  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => { prefs.view = b.dataset.view; savePrefs(); render(); }));
  $('q').addEventListener('input', renderList);
  $('list').addEventListener('click', async (e) => {
    const d = e.target.closest('[data-done]');
    if (d) {
      const t = entries.get(d.dataset.done);
      if (!t) return;
      const next = Object.assign({}, t, { status: t.status === 'Completed' ? 'New' : 'Completed' });
      try { await putEntry(next); toast(next.status === 'Completed' ? 'Completed ✓' : 'Moved back to open'); } catch (err) { toast(err.message); }
      return;
    }
    const o = e.target.closest('[data-open]');
    if (o) { openEditor(o.dataset.open); return; }
    const i = e.target.closest('[data-idea]');
    if (i) openIdea(i.dataset.idea);
  });

  /* ---------- sheets ---------- */
  const openSheet = (id) => { $(id).hidden = false; document.body.style.overflow = 'hidden'; };
  const closeSheet = (id) => { $(id).hidden = true; if (['edScrim', 'mScrim', 'iScrim', 'bwScrim'].every((s) => $(s).hidden)) document.body.style.overflow = ''; };
  ['edScrim', 'mScrim', 'iScrim', 'bwScrim'].forEach((s) => $(s).addEventListener('click', (e) => { if (e.target === $(s)) closeSheet(s); }));
  const armDelete = (btn, label) => { if (!btn.classList.contains('armed')) { btn.classList.add('armed'); btn.textContent = 'Tap again to delete'; return false; } return true; };
  const resetDelete = (btn) => { btn.classList.remove('armed'); btn.textContent = 'Delete'; };
  $('catList').innerHTML = CATS.map((c) => `<option value="${c}">`).join('');

  /* rich text: every .rte on the page */
  document.querySelectorAll('.rte').forEach((r) => {
    const body = r.querySelector('.rte-body');
    r.querySelectorAll('.rte-tools button').forEach((b) => {
      b.addEventListener('mousedown', (e) => e.preventDefault());
      b.addEventListener('click', () => {
        body.focus();
        const c = b.dataset.cmd;
        if (c === 'h3') { const cur = String(document.queryCommandValue('formatBlock')).toLowerCase(); document.execCommand('formatBlock', false, cur === 'h3' ? 'P' : 'H3'); }
        else document.execCommand(c, false, null);
      });
    });
    body.addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain')); });
  });
  const rteVal = (id) => ($(id).textContent.trim() ? $(id).innerHTML : '');

  /* ---------- task editor ---------- */
  let editing = null;
  function openEditor(id) {
    const t = entries.get(id);
    if (!t) return;
    editing = Object.assign({}, t);
    const src = t.source ? ` from “${esc(t.source.length > 140 ? t.source.slice(0, 140) + '…' : t.source)}”` : '';
    $('edSrc').innerHTML = (t.createdAt ? `Added ${fmt(t.createdAt.slice(0, 10))}` : '') + src + (t.doneAt ? ` · completed ${fmt(t.doneAt)}` : '');
    $('eTitle').value = t.title || '';
    $('eMission').innerHTML = missionOptions(t.mission ? 'm:' + t.mission : '', false);
    $('eStatus').value = t.status || 'New';
    $('eDue').value = t.due || '';
    $('eTime').value = t.time || '';
    $('eProgress').innerHTML = t.progress || '';
    $('eRemark').innerHTML = t.remark || '';
    $('eBw').innerHTML = bwOptions(t.bandwidth) + (bwById(t.bandwidth) ? '' : `<option value="${esc(t.bandwidth)}" selected>Unassigned</option>`);
    $('ePri').value = t.priority || 'Medium';
    $('eEff').value = t.effort || 30;
    $('eCat').value = t.category || '';
    $('ePerson').value = t.person || '';
    resetDelete($('eDel'));
    bwHelp();
    openSheet('edScrim');
    setTimeout(() => $('eTitle').focus(), 30);
  }
  function bwHelp() { const b = bwById($('eBw').value); $('eBwHelp').textContent = b ? b.when : ''; }
  $('eBw').addEventListener('change', bwHelp);
  $('eDueClear').addEventListener('click', () => { $('eDue').value = ''; });
  $('eTimeClear').addEventListener('click', () => { $('eTime').value = ''; });
  $('eCancel').addEventListener('click', () => closeSheet('edScrim'));
  $('edForm').addEventListener('submit', (e) => { e.preventDefault(); saveEditor(); });
  $('eSave').addEventListener('click', saveEditor);
  $('eDel').addEventListener('click', async () => {
    if (!armDelete($('eDel'))) return;
    const id = editing.id;
    closeSheet('edScrim');
    try { await delEntry(id); toast('Task deleted'); } catch (err) { toast(err.message); }
  });
  async function saveEditor() {
    const title = $('eTitle').value.trim();
    if (!title) { $('eTitle').focus(); return; }
    const ms = $('eMission').value;
    const t = Object.assign({}, editing, {
      kind: 'task', title, mission: ms.startsWith('m:') ? ms.slice(2) : '', status: $('eStatus').value,
      due: $('eDue').value, time: $('eTime').value, progress: rteVal('eProgress'), remark: rteVal('eRemark'),
      bandwidth: $('eBw').value, priority: $('ePri').value, effort: Math.max(5, +$('eEff').value || 30),
      category: $('eCat').value.trim(), person: $('ePerson').value.trim(),
    });
    $('eSave').disabled = true;
    try { await putEntry(t); closeSheet('edScrim'); toast('Saved'); } catch (err) { toast(err.message); }
    $('eSave').disabled = false;
  }

  /* ---------- mission editor ---------- */
  let mEditing = null;
  function openMission(id) {
    const m = id ? missions.get(id) : null;
    mEditing = m ? Object.assign({}, m) : { id: '', title: '', goal: '', target: '', status: 'Active', hue: HUES.find((h) => !missionList().some((x) => x.hue === h && x.status === 'Active')) || HUES[missions.size % HUES.length] };
    $('mTitleH').textContent = m ? 'Edit mission' : 'New mission';
    $('mTitle').value = mEditing.title;
    $('mGoal').value = mEditing.goal;
    $('mTarget').value = mEditing.target;
    $('mStatus').value = mEditing.status;
    renderHues();
    const s = m ? mStats(m.id) : null;
    $('mHelp').textContent = m ? `${s.total} task${s.total === 1 ? '' : 's'} in this mission, ${s.done} completed. Deleting the mission keeps its tasks.` : 'After saving, add tasks from the box above while the mission is selected, or pick the mission in a task.';
    $('mDel').hidden = !m;
    resetDelete($('mDel'));
    openSheet('mScrim');
    setTimeout(() => $('mTitle').focus(), 30);
  }
  function renderHues() {
    $('mHues').innerHTML = HUES.map((h) => `<button type="button" class="hue c-${h}" data-hue="${h}" aria-label="${h}" aria-pressed="${mEditing.hue === h}"></button>`).join('');
    $('mHues').querySelectorAll('[data-hue]').forEach((b) => (b.onclick = () => { mEditing.hue = b.dataset.hue; renderHues(); }));
  }
  $('newMission').addEventListener('click', () => openMission(''));
  $('mTargetClear').addEventListener('click', () => { $('mTarget').value = ''; });
  $('mCancel').addEventListener('click', () => closeSheet('mScrim'));
  $('mForm').addEventListener('submit', (e) => { e.preventDefault(); saveMission(); });
  $('mSave').addEventListener('click', saveMission);
  async function saveMission() {
    const title = $('mTitle').value.trim();
    if (!title) { $('mTitle').focus(); return; }
    const isNew = !mEditing.id;
    $('mSave').disabled = true;
    try {
      const m = await putMission(Object.assign({}, mEditing, { title, goal: $('mGoal').value.trim(), target: $('mTarget').value, status: $('mStatus').value }));
      closeSheet('mScrim');
      if (isNew) { prefs.mission = m.id; prefs.view = 'open'; savePrefs(); render(); prompt.focus(); }
      toast(isNew ? 'Mission created — now add its tasks' : 'Mission saved');
    } catch (err) { toast(err.message); }
    $('mSave').disabled = false;
  }
  $('mDel').addEventListener('click', async () => {
    if (!armDelete($('mDel'))) return;
    const id = mEditing.id;
    closeSheet('mScrim');
    try {
      await api('mission_delete', { id });
      missions.delete(id);
      entries.forEach((t) => { if (t.mission === id) t.mission = ''; });
      if (prefs.mission === id) prefs.mission = '';
      savePrefs(); render(); toast('Mission deleted; its tasks were kept');
    } catch (err) { toast(err.message); }
  });

  /* ---------- idea editor ---------- */
  let iEditing = null;
  function openIdea(id) {
    const i = entries.get(id);
    if (!i) return;
    iEditing = Object.assign({}, i);
    $('iSrc').textContent = i.createdAt ? `Saved ${fmt(i.createdAt.slice(0, 10))}` : '';
    $('iTitle').value = i.title;
    $('iNotes').innerHTML = i.notes || '';
    $('iCat').value = i.category || '';
    resetDelete($('iDel'));
    openSheet('iScrim');
    setTimeout(() => $('iTitle').focus(), 30);
  }
  $('iCancel').addEventListener('click', () => closeSheet('iScrim'));
  $('iForm').addEventListener('submit', (e) => { e.preventDefault(); saveIdea(); });
  $('iSave').addEventListener('click', saveIdea);
  async function saveIdea() {
    const title = $('iTitle').value.trim();
    if (!title) { $('iTitle').focus(); return; }
    try { await putEntry(Object.assign({}, iEditing, { kind: 'idea', title, notes: rteVal('iNotes'), category: $('iCat').value.trim() })); closeSheet('iScrim'); toast('Idea saved'); }
    catch (err) { toast(err.message); }
  }
  $('iDel').addEventListener('click', async () => {
    if (!armDelete($('iDel'))) return;
    const id = iEditing.id;
    closeSheet('iScrim');
    try { await delEntry(id); toast('Idea deleted'); } catch (err) { toast(err.message); }
  });
  $('iToTask').addEventListener('click', async () => {
    const title = $('iTitle').value.trim() || iEditing.title;
    try {
      const t = await putEntry(Object.assign({}, iEditing, { kind: 'task', title, status: 'New', remark: rteVal('iNotes'), notes: '', bandwidth: guessBw(title).id, category: $('iCat').value.trim() }));
      closeSheet('iScrim');
      prefs.view = 'open'; savePrefs(); render();
      openEditor(t.id);
      toast('Idea turned into a task');
    } catch (err) { toast(err.message); }
  });

  /* ---------- bandwidth settings (each user edits their own) ---------- */
  const DAY_L = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
  let bwDraft = [];
  $('openSettings').addEventListener('click', () => {
    bwDraft = bws.map((b) => Object.assign({}, b, { days: [...b.days] }));
    renderBwSettings();
    openSheet('bwScrim');
  });
  $('bwCancel').addEventListener('click', () => closeSheet('bwScrim'));
  $('bwReset').addEventListener('click', () => {
    const used = new Set(tasks().map((t) => t.bandwidth));
    const defs = (window.RC_DEFAULT_BW || []).map((b) => Object.assign({}, b, { days: [...b.days] }));
    // keep any of your own bandwidths that still have tasks
    bwDraft = defs.concat(bwDraft.filter((b) => used.has(b.id) && !defs.some((d) => d.id === b.id)));
    renderBwSettings();
    toast('Defaults loaded — Save to keep them');
  });
  function renderBwSettings() {
    const used = (id) => tasks().some((t) => t.bandwidth === id);
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
    try { const d = await api('bandwidths_save', { list }); bws = d.bandwidths; closeSheet('bwScrim'); render(); toast('Bandwidths saved'); }
    catch (err) { toast(err.message); }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    for (const s of ['edScrim', 'mScrim', 'iScrim', 'bwScrim']) if (!$(s).hidden) { closeSheet(s); return; }
    if (comp.classList.contains('open') && document.activeElement === prompt && !prompt.value) { comp.classList.remove('open'); prompt.blur(); }
  });

  // pick up changes made on another device when you come back to the tab
  document.addEventListener('visibilitychange', () => { if (!document.hidden && ready) refresh(); });
  setInterval(() => { if (ready) { renderBws(); if (prefs.view === 'open') renderList(); } }, 60000);
  if (location.hash === '#add') setTimeout(() => prompt.focus(), 50);
  const phPrompt = () => { prompt.placeholder = window.innerWidth < 700 ? 'Add a task, mission or idea…' : 'What needs doing? e.g. File ITR: collect Form 16, call CA by Friday · idea: …'; };
  phPrompt(); window.addEventListener('resize', phPrompt);

  loadAll().catch((e) => { $('list').innerHTML = `<div class="empty">Could not load. ${esc(e.message)}</div>`; });
})();
