// ProjectDesk – dialog helpers (vanilla JS, no libraries)
function fill(form, data) {
  for (const [k, v] of Object.entries(data)) {
    const el = form.elements.namedItem(k);
    if (el) el.value = v == null ? '' : v;
  }
}
function openTask(t) {
  const d = document.getElementById('dTask'), f = d.querySelector('form');
  const isNew = !t;
  t = t || { id: '', project_id: PD.proj || (f.elements.namedItem('project_id').options[0] || {}).value || '', name: '', owner_id: PD.me,
             target_date: PD.plus7, effort: 8, status: 'Not Started', remark: '' };
  fill(f, { tid: t.id, project_id: t.project_id, name: t.name, owner_id: t.owner_id, target_date: t.target_date,
            effort: t.effort, status: t.status, remark: t.remark });
  d.querySelector('.dt').textContent = isNew ? 'New Task' : 'Task Information';
  f.querySelectorAll('.a').forEach(el => el.disabled = !PD.admin);
  const del = f.querySelector('.del'); if (del) del.style.display = isNew ? 'none' : '';
  d.showModal();
  (PD.admin ? f.elements.namedItem('name') : f.elements.namedItem('status')).focus();
}
function openProject(p) {
  const d = document.getElementById('dProject'); if (!d) return;
  const f = d.querySelector('form'), isNew = !p;
  p = p || { id: '', name: '', goal: '', owner_id: PD.me, start_date: PD.today, target_date: PD.plus60 };
  fill(f, { pid: p.id, name: p.name, goal: p.goal, owner_id: p.owner_id, start_date: p.start_date, target_date: p.target_date });
  d.querySelector('.dt').textContent = isNew ? 'New Project' : 'Project Information';
  f.querySelector('.del').style.display = isNew ? 'none' : '';
  d.showModal(); f.elements.namedItem('name').focus();
}
function openUser(u) {
  const d = document.getElementById('dUser'), f = d.querySelector('form'), isNew = !u;
  u = u || { id: '', name: '', email: '', role: 'User', active: 1 };
  fill(f, { uid: u.id, name: u.name, email: u.email, role: u.role, active: u.active, password: '' });
  f.elements.namedItem('password').required = isNew;
  d.querySelector('.pwnote').textContent = isNew ? 'Set an initial password and share it with the user.' : 'Leave password blank to keep it. Type a new one to reset it.';
  d.querySelector('.dt').textContent = isNew ? 'Add User' : 'Edit User';
  d.showModal(); f.elements.namedItem('name').focus();
}
// close a dialog by clicking the backdrop
document.addEventListener('click', e => { if (e.target.tagName === 'DIALOG') e.target.close(); });
// auto-hide success messages
setTimeout(() => { const f = document.querySelector('.flash.ok'); if (f) f.remove(); }, 5000);
