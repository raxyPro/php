<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/util.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars(APP_TITLE) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f6f7fb; }
    header { padding:12px 16px; background:#111827; color:#fff; display:flex; align-items:center; gap:12px; }
    header .muted { color:#9ca3af; font-size: 13px; }
    .app { display:flex; height: calc(100vh - 52px); }
    .col { padding:12px; overflow:auto; }
    .left { width: 280px; background:#fff; border-right:1px solid #e5e7eb; }
    .center { flex: 1; background:#fff; border-right:1px solid #e5e7eb; }
    .right { width: 420px; background:#fff; }

    h3 { margin: 12px 0 8px; font-size: 14px; color:#111827; }
    .row { display:flex; gap:8px; align-items:center; }
    input, textarea, select { width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; }
    textarea { min-height: 70px; resize: vertical; }
    button { padding:8px 10px; border:1px solid #e5e7eb; border-radius:10px; background:#111827; color:#fff; cursor:pointer; }
    button.secondary { background:#fff; color:#111827; }
    button.danger { background:#b91c1c; border-color:#b91c1c; }
    button:disabled { opacity:.5; cursor:not-allowed; }

    .list { border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
    .item { padding:10px; border-bottom:1px solid #e5e7eb; cursor:pointer; }
    .item:last-child { border-bottom:none; }
    .item.active { background:#f3f4f6; }
    .small { font-size:12px; color:#6b7280; }
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:12px; margin-right:6px; }

    .topbar { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .topbar input { flex:1; }
    .dropHint { border:2px dashed #cbd5e1; border-radius:12px; padding:10px; text-align:center; color:#64748b; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New"; font-size:12px; color:#6b7280; }

    .status { padding:8px 12px; border-top:1px solid #e5e7eb; font-size:13px; color:#374151; background:#f9fafb; }
    .tagInputHelp { font-size:12px; color:#6b7280; margin-top:6px; }
  </style>
</head>
<body>
<header>
  <strong><?= htmlspecialchars(APP_TITLE) ?></strong>
  <span class="muted">Shared MySQL • URL-only attachments</span>
</header>

<div class="app">
  <!-- LEFT -->
  <div class="col left">
    <h3>Folders</h3>
    <div class="row">
      <input id="folderName" placeholder="New folder name (e.g., Client A)" />
      <button id="btnCreateFolder">+</button>
    </div>
    <div class="row" style="margin-top:8px;">
      <button class="secondary" id="btnRefreshFolders">Refresh</button>
      <button class="danger" id="btnRemoveFolder" disabled>Remove</button>
    </div>
    <div style="margin-top:10px;" class="list" id="foldersList"></div>

    <h3 style="margin-top:16px;">Tags</h3>
    <input id="tagSearch" placeholder="Search tags..." />
    <div class="row" style="margin-top:8px;">
      <input id="newTagName" placeholder="Create tag (type + Enter)" />
      <button id="btnCreateTag">+</button>
    </div>
    <div style="margin-top:10px;" class="list" id="tagsList"></div>
  </div>

  <!-- CENTER -->
  <div class="col center">
    <div class="topbar">
      <button id="btnAddEvent" disabled>+ Add Event</button>
      <input id="eventSearch" placeholder="Search events..." />
      <button class="secondary" id="btnClearTagFilter">Clear Tag</button>
    </div>
    <div class="small" id="filterInfo"></div>
    <div class="list" id="eventsList" style="margin-top:10px;"></div>
  </div>

  <!-- RIGHT -->
    <div class="col right">
    <h3>Event Details</h3>
    <div class="small" id="selectedEventInfo">No event selected</div>
    <div style="margin-top:10px;">
      <label class="small">Date & time</label>
      <input id="evDate" placeholder="YYYY-MM-DD HH:MM" />
    </div>
    <div style="margin-top:8px;">
      <label class="small">Name</label>
      <input id="evName" placeholder="Event name" />
    </div>
    <div style="margin-top:8px;">
      <label class="small">Description</label>
      <textarea id="evDesc" placeholder="Event description"></textarea>
    </div>
    <div style="margin-top:8px;">
      <label class="small">Remark</label>
      <textarea id="evRemark" placeholder="Event remark"></textarea>
    </div>
    <div style="margin-top:8px;">
      <label class="small">Tags (comma separated)</label>
      <input id="evTagsCsv" placeholder="e.g., meeting, finance, urgent" />
      <div class="tagInputHelp">Tip: Type new tags here; they’ll be created automatically on Save.</div>
    </div>

    <div class="row" style="margin-top:10px;">
      <button id="btnSaveEvent" disabled>Save</button>
      <button class="secondary" id="btnAddUrl" disabled>Add URL</button>
    </div>

    <h3 style="margin-top:14px;">Linked Files</h3>
    <div class="list" id="linkedFilesList" style="margin-top:10px;"></div>
  </div>

  <div class="status"' id="status">Ready.</div>

<script>
let state = {
  folderId: null,
  folders: [],
  tagFilterId: null,
  selectedEventId: null,
  tags: [],
};

function qs(id){ return document.getElementById(id); }
function setStatus(msg){ qs('status').textContent = msg; }

async function apiGet(action, params={}){
  const url = new URL('api.php', window.location.href);
  url.searchParams.set('action', action);
  for (const [k,v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url.toString());
  const data = await res.json();
  if(!data.ok) throw new Error(data.error || 'API error');
  return data;
}

async function apiPost(action, formObj, filesFormData=null){
  const url = new URL('api.php', window.location.href);
  url.searchParams.set('action', action);

  let body;
  if (filesFormData) {
    body = filesFormData;
  } else {
    body = new URLSearchParams();
    for (const [k,v] of Object.entries(formObj)) body.set(k, v);
  }

  const res = await fetch(url.toString(), { method:'POST', body });
  const data = await res.json();
  if(!data.ok) throw new Error(data.error || 'API error');
  return data;
}

// ----- FOLDERS -----
async function loadFolders(){
  const data = await apiGet('folders.list');
  state.folders = data.folders;
  renderFolders();
  qs('btnAddEvent').disabled = !state.folderId;
}

function renderFolders(){
  const box = qs('foldersList');
  box.innerHTML = '';
  state.folders.forEach(f=>{
    const div = document.createElement('div');
    div.className = 'item' + (state.folderId===+f.id ? ' active':'' );
    div.innerHTML = `<div><strong>${escapeHtml(f.name)}</strong></div>`;
    div.onclick = async ()=>{
      state.folderId = +f.id;
      state.tagFilterId = null;
      state.selectedEventId = null;
      qs('btnRemoveFolder').disabled = false;
      qs('btnAddEvent').disabled = false;
      qs('filterInfo').textContent = '';
      await reloadFolderContext();
    };
    box.appendChild(div);
  });

  if (!state.folders.length) {
    box.innerHTML = `<div class="item small">No folders yet. Create one above.</div>`;
    qs('btnRemoveFolder').disabled = true;
  }
}

async function createFolder(){
  const name = qs('folderName').value.trim();
  if(!name) return setStatus('Folder name required.');
  const data = await apiPost('folders.create', { name });
  qs('folderName').value='';
  setStatus('Folder created.');
  await loadFolders();
}

async function removeFolder(){
  if(!state.folderId) return;
  const f = state.folders.find(x=>+x.id===state.folderId);
  if(!confirm(`Remove folder "${f?.name || ''}" from registry?\n(Note: files on disk are NOT deleted)`)) return;
  await apiPost('folders.remove', { id: state.folderId });
  state.folderId = null;
  state.tagFilterId = null;
  state.selectedEventId = null;
  setStatus('Folder removed from registry.');
  await loadFolders();
  clearRightPane();
  renderTags([]);
  renderEvents([]);
}

// ----- TAGS -----
async function loadTags(){
  if(!state.folderId) return;
  const data = await apiGet('tags.list', { folder_id: state.folderId });
  state.tags = data.tags;
  renderTags(state.tags);
}

function renderTags(tags){
  const box = qs('tagsList');
  box.innerHTML = '';
  const search = qs('tagSearch').value.trim().toLowerCase();
  const filtered = tags.filter(t => t.name.toLowerCase().includes(search));
  filtered.forEach(t=>{
    const div = document.createElement('div');
    div.className = 'item' + (state.tagFilterId===+t.id ? ' active':'' );
    div.innerHTML = `<span class="pill">#</span> ${escapeHtml(t.name)}`;
    div.onclick = async ()=>{
      state.tagFilterId = +t.id;
      await loadEvents();
      qs('filterInfo').textContent = `Tag filter: ${t.name}`;
    };
    box.appendChild(div);
  });
  if(!filtered.length) box.innerHTML = `<div class="item small">No tags.</div>`;
}

async function createTag(){
  const name = qs('newTagName').value.trim();
  if(!state.folderId) return setStatus('Select a folder first.');
  if(!name) return setStatus('Tag name required.');
  await apiPost('tags.create', { folder_id: state.folderId, name });
  qs('newTagName').value='';
  setStatus('Tag created.');
  await loadTags();
}

// ----- EVENTS -----
async function loadEvents(){
  if(!state.folderId) return;
  const q = qs('eventSearch').value.trim();
  const params = { folder_id: state.folderId };
  if(q) params.q = q;
  if(state.tagFilterId) params.tag_id = state.tagFilterId;
  const data = await apiGet('events.list', params);
  renderEvents(data.events);
}

function renderEvents(events){
  const box = qs('eventsList');
  box.innerHTML = '';
  if(!events.length){
    box.innerHTML = `<div class="item small">No events. Click “+ Add Event”.</div>`;
    return;
  }
  events.forEach(ev=>{
    const div = document.createElement('div');
    div.className = 'item' + (state.selectedEventId===+ev.id ? ' active':'' );
    const tags = (ev.tags||[]).map(t=>`<span class="pill">${escapeHtml(t.name)}</span>`).join(' ');
    div.innerHTML = `
      <div style="display:flex; justify-content:space-between; gap:10px;">
        <div>
          <div><strong>${escapeHtml(ev.name)}</strong></div>
          <div class="small">${escapeHtml(ev.event_date)} • ${ev.file_count} file(s)</div>
          <div style="margin-top:6px;">${tags}</div>
        </div>
        <div class="small mono">#${ev.id}</div>
      </div>
    `;

    div.onclick = async ()=>{
      state.selectedEventId = +ev.id;
      await loadEventDetails(state.selectedEventId);
      renderEvents(events);
    };
    box.appendChild(div);
  });
}

async function addEvent(){
  if(!state.folderId) return setStatus('Select a folder first.');
  const now = new Date();
  const pad = n => String(n).padStart(2,'0');
  const dt = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

  const data = await apiPost('events.create', {
    folder_id: state.folderId,
    event_date: dt,
    name: 'New Event',
    description: '',
    remark: '',
    tags_csv: ''
  });
  state.selectedEventId = +data.event.id;
  setStatus('Event created.');
  await loadEvents();
  await loadEventDetails(state.selectedEventId);
}

async function loadEventDetails(eventId){
  if(!state.folderId) return;
  const data = await apiGet('events.get', { folder_id: state.folderId, event_id: eventId });
  const ev = data.event;
  qs('selectedEventInfo').textContent = ev ? `Selected: #${ev.id}` : 'No event selected';
  qs('evDate').value = ev?.event_date || '';
  qs('evName').value = ev?.name || '';
  qs('evDesc').value = ev?.description || '';
  qs('evRemark').value = ev?.remark || '';
  qs('evTagsCsv').value = (ev?.tags || []).map(t=>t.name).join(', ');
  qs('btnSaveEvent').disabled = !ev;
  qs('btnAddUrl').disabled = !ev;
  renderLinkedFiles(ev?.files || []);
}

function clearRightPane(){
  qs('selectedEventInfo').textContent = 'No event selected';
  qs('evDate').value='';
  qs('evName').value='';
  qs('evDesc').value='';
  qs('evRemark').value='';
  qs('evTagsCsv').value='';
  qs('btnSaveEvent').disabled = true;
  qs('btnAddUrl').disabled = true;
  renderLinkedFiles([]);
}

async function saveEvent(){
  if(!state.folderId || !state.selectedEventId) return;
  const data = await apiPost('events.update', {
    folder_id: state.folderId,
    event_id: state.selectedEventId,
    event_date: qs('evDate').value.trim(),
    name: qs('evName').value.trim(),
    description: qs('evDesc').value,
    remark: qs('evRemark').value,
    tags_csv: qs('evTagsCsv').value
  });
  setStatus('Event saved.');
  await loadTags();
  await loadEvents();
  await loadEventDetails(state.selectedEventId);
}

async function addUrl(){
  if(!state.selectedEventId) return;
  const url = prompt('Paste URL (http(s) or any text):');
  if(!url) return;
  await linkFileToEvent(state.selectedEventId, {
    file_url: url.trim(),
    display_name: url.trim(),
    file_type: 'url'
  });
  await loadEventDetails(state.selectedEventId);
  await loadEvents();
}

async function linkFileToEvent(eventId, file){
  await apiPost('events.link_file', {
    folder_id: state.folderId,
    event_id: eventId,
    file_url: file.file_url || '',
    display_name: file.display_name || '',
    file_type: file.file_type || ''
  });
}

function renderLinkedFiles(files){
  const box = qs('linkedFilesList');
  box.innerHTML = '';
  if(!files.length){
    box.innerHTML = `<div class="item small">No linked files yet.</div>`;
    return;
  }
  files.forEach(f=>{
    const div = document.createElement('div');
    div.className = 'item';
    const url = f.file_url || '';
    const link = url;
    div.innerHTML = `
      <div style="display:flex; justify-content:space-between; gap:10px;">
        <div>
          <div><a href="${escapeAttr(link)}" target="_blank">${escapeHtml(f.display_name || f.file_url)}</a></div>
        </div>
        <button class="danger" style="padding:6px 8px;" title="Remove">X</button>
      </div>
    `;
    div.querySelector('button').onclick = async ()=>{
      await apiPost('events.remove_file', { folder_id: state.folderId, event_file_id: f.id });
      setStatus('Linked file removed.');
      await loadEventDetails(state.selectedEventId);
      await loadEvents();
    };
    box.appendChild(div);
  });
}

async function reloadFolderContext(){
  setStatus('Loading folder...');
  await loadTags();
  await loadEvents();
  clearRightPane();
  setStatus('Ready.');
}

// ----- HELPERS -----
function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c]));
}
function escapeAttr(s){ return escapeHtml(s); }

// ----- WIREUP -----
qs('btnCreateFolder').onclick = ()=>createFolder().catch(e=>setStatus(e.message));
qs('btnRefreshFolders').onclick = ()=>loadFolders().catch(e=>setStatus(e.message));
qs('btnRemoveFolder').onclick = ()=>removeFolder().catch(e=>setStatus(e.message));
qs('btnCreateTag').onclick = ()=>createTag().catch(e=>setStatus(e.message));
qs('btnAddEvent').onclick = ()=>addEvent().catch(e=>setStatus(e.message));
qs('btnSaveEvent').onclick = ()=>saveEvent().catch(e=>setStatus(e.message));
qs('btnAddUrl').onclick = ()=>addUrl().catch(e=>setStatus(e.message));

qs('eventSearch').addEventListener('input', ()=>loadEvents().catch(e=>setStatus(e.message)));
qs('tagSearch').addEventListener('input', ()=>renderTags(state.tags));
qs('btnClearTagFilter').onclick = async ()=>{
  state.tagFilterId = null;
  qs('filterInfo').textContent = '';
  await loadEvents();
};

// Enter-to-create tag
qs('newTagName').addEventListener('keydown', (e)=>{
  if(e.key === 'Enter'){ e.preventDefault(); createTag().catch(err=>setStatus(err.message)); }
});

loadFolders().catch(e=>setStatus(e.message));
</script>
</body>
</html>



