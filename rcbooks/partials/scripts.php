<script>
  const CSRF = <?= json_encode($csrf) ?>;
  const SELECTED_BOOK_ID = <?= (int) $selectedBookId ?>;
  const SELECTED_CHAPTER_ID = <?= (int) $selectedChapterId ?>;
  const SELECTED_TOPIC_ID = <?= (int) $selectedTopicId ?>;

  let quill = null;
  let dirty = false;
  let saving = false;
  let autosaveTimer = null;

  function setStatus(msg, kind = 'muted') {
    const el = document.getElementById('saveStatus');
    if (!el) return;
    el.textContent = msg;
    el.style.color =
      kind === 'good' ? '<?= "#22c55e" ?>' :
      kind === 'warn' ? '<?= "#f59e0b" ?>' :
      kind === 'bad' ? '<?= "#ef4444" ?>' :
      '<?= "#9fb0cc" ?>';
  }

  async function postAction(action, payload) {
    const form = new FormData();
    form.append('action', action);
    form.append('csrf', CSRF);
    for (const [k, v] of Object.entries(payload || {})) {
      form.append(k, v);
    }
    const res = await fetch(location.pathname, { method: 'POST', body: form });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid JSON' }));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  function openModal(kind) {
    const bg = document.getElementById('modalBg');
    const title = document.getElementById('modalTitle');
    const body = document.getElementById('modalBody');

    if (kind === 'book') {
      title.textContent = 'Create Book';
      body.innerHTML = `
        <div class="grid2">
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Title</div>
            <input id="bookTitle" class="field" placeholder="e.g., Agile Metrics Book">
          </div>
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Description (optional)</div>
            <input id="bookDesc" class="field" placeholder="Short description">
          </div>
        </div>
        <div style="margin-top:12px" class="row">
          <button class="btn" onclick="createBook()">Create</button>
          <span class="muted" style="font-size:12px">After creating, select the book from left.</span>
        </div>
      `;
    }

    if (kind === 'chapter') {
      title.textContent = 'Create Chapter';
      body.innerHTML = `
        <div class="grid2">
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Chapter Title</div>
            <input id="chapterTitle" class="field" placeholder="e.g., Burn Down Chart">
          </div>
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Description</div>
            <input id="chapterDescription" class="field" placeholder="Short description">
          </div>
        </div>
        <div style="margin-top:12px" class="row">
          <button class="btn" onclick="createChapter()">Create</button>
          <span class="muted" style="font-size:12px">Book #${SELECTED_BOOK_ID}</span>
        </div>
      `;
    }

    if (kind === 'topic') {
      title.textContent = 'Add Topic';
      body.innerHTML = `
        <div>
          <div class="muted" style="font-size:12px;margin-bottom:6px">Topic Name</div>
          <input id="topicName" class="field" placeholder="e.g., Definition & Formula">
        </div>
        <div style="margin-top:10px">
          <div class="muted" style="font-size:12px;margin-bottom:6px">Topic Description</div>
          <textarea id="topicDesc" class="field" placeholder="Short explanation / key points"></textarea>
        </div>
        <div style="margin-top:12px" class="row">
          <button class="btn" onclick="createTopic()">Add</button>
          <span class="muted" style="font-size:12px">Book #${SELECTED_BOOK_ID}</span>
        </div>
      `;
    }

    bg.style.display = 'flex';
  }

  function closeModal() {
    document.getElementById('modalBg').style.display = 'none';
  }

  async function createBook() {
    const title = (document.getElementById('bookTitle').value || '').trim();
    const description = (document.getElementById('bookDesc').value || '').trim();
    if (!title) {
      alert('Book title required');
      return;
    }
    try {
      await postAction('create_book', { title, description });
      location.href = location.pathname;
    } catch (e) {
      alert(e.message);
    }
  }

  async function createChapter() {
    const title = (document.getElementById('chapterTitle').value || '').trim();
    const description = (document.getElementById('chapterDescription').value || '').trim();
    if (!title) {
      alert('Chapter title required');
      return;
    }
    try {
      const out = await postAction('create_chapter', { book_id: String(SELECTED_BOOK_ID), title, description });
      location.href = `?book=${SELECTED_BOOK_ID}&chapter=${out.id}`;
    } catch (e) {
      alert(e.message);
    }
  }

  async function createTopic() {
    const name = (document.getElementById('topicName').value || '').trim();
    const description = (document.getElementById('topicDesc').value || '').trim();
    if (!name) {
      alert('Topic name required');
      return;
    }
    try {
      const out = await postAction('create_topic', { book_id: String(SELECTED_BOOK_ID), name, description });
      location.href = `?book=${SELECTED_BOOK_ID}${SELECTED_CHAPTER_ID ? `&chapter=${SELECTED_CHAPTER_ID}` : ''}&topic=${out.id}`;
    } catch (e) {
      alert(e.message);
    }
  }

  async function linkTopic(topicId) {
    try {
      await postAction('link_topic', {
        chapter_id: String(SELECTED_CHAPTER_ID),
        topic_id: String(topicId)
      });
      location.reload();
    } catch (e) {
      alert(e.message);
    }
  }

  async function unlinkTopic(topicId) {
    try {
      await postAction('unlink_topic', {
        chapter_id: String(SELECTED_CHAPTER_ID),
        topic_id: String(topicId)
      });
      location.reload();
    } catch (e) {
      alert(e.message);
    }
  }

  async function deleteTopic(topicId) {
    if (!confirm('Delete this topic?')) return;
    try {
      await postAction('delete_topic', { topic_id: String(topicId) });
      const el = document.querySelector(`[data-topic-id="${topicId}"]`);
      if (el) el.remove();
    } catch (e) {
      alert(e.message);
    }
  }

  function initEditor() {
    if (!SELECTED_CHAPTER_ID && !SELECTED_TOPIC_ID) return;

    const toolbar = [
      [{ 'header': [1, 2, 3, 4, false] }],
      ['bold', 'italic', 'underline', 'strike'],
      [{ 'list': 'ordered' }, { 'list': 'bullet' }],
      [{ 'align': [] }],
      ['blockquote', 'code-block'],
      ['link', 'image'],
      [{ 'color': [] }, { 'background': [] }],
      ['clean']
    ];

    quill = new Quill('#editor', {
      theme: 'snow',
      modules: { toolbar }
    });

    const initialHtml = <?= json_encode($topic ? (string) ($topic['content_html'] ?? '') : ($chapter ? (string) ($chapter['detail_html'] ?? '') : '')) ?>;
    if (initialHtml) {
      quill.clipboard.dangerouslyPasteHTML(initialHtml);
    }

    const trackedFields = SELECTED_TOPIC_ID
      ? ['topicNameField', 'topicDescriptionField']
      : ['chapterTitleField', 'chapterDescriptionField'];

    for (const fieldId of trackedFields) {
      const field = document.getElementById(fieldId);
      if (!field) continue;
      field.addEventListener('input', () => {
        dirty = true;
        setStatus('Unsaved changes...', 'warn');
      });
    }

    quill.on('text-change', () => {
      dirty = true;
      setStatus('Unsaved changes...', 'warn');
    });

    autosaveTimer = setInterval(async () => {
      if (!dirty) return;
      await saveChapter();
    }, 60000);

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        manualSave();
      }
    });

    window.addEventListener('beforeunload', (e) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    setStatus('Ready', 'muted');
  }

  function plainTextFromQuill() {
    return (quill ? quill.getText() : '').trim();
  }

  async function saveChapter() {
    if (!quill || saving) return;
    saving = true;
    setStatus('Saving...', 'muted');

    try {
      const html = quill.root.innerHTML;
      const text = plainTextFromQuill();
      if (SELECTED_TOPIC_ID) {
        const name = (document.getElementById('topicNameField').value || '').trim();
        const description = (document.getElementById('topicDescriptionField').value || '').trim();
        if (!name) {
          throw new Error('Topic name required');
        }
        await postAction('save_topic', {
          topic_id: String(SELECTED_TOPIC_ID),
          name,
          description,
          content_html: html,
          content_text: text
        });
      } else {
        const title = (document.getElementById('chapterTitleField').value || '').trim();
        const description = (document.getElementById('chapterDescriptionField').value || '').trim();
        if (!title) {
          throw new Error('Chapter title required');
        }
        await postAction('save_chapter', {
          chapter_id: String(SELECTED_CHAPTER_ID),
          title,
          description,
          detail_html: html,
          detail_text: text
        });
      }
      dirty = false;
      setStatus('Saved OK', 'good');
    } catch (e) {
      setStatus('Save failed', 'bad');
      alert(e.message || 'Save failed');
      console.error(e);
    } finally {
      saving = false;
    }
  }

  function manualSave() {
    saveChapter();
  }

  initEditor();
</script>
