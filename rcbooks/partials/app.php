<div class="app">
  <div class="panel">
    <div class="hdr">
      <h3>Books & Chapters</h3>
      <button class="btn" onclick="openModal('book')">+ Book</button>
    </div>
    <div class="body split">
      <div>
        <div class="row" style="justify-content:space-between;margin-bottom:8px">
          <div class="pill">Books</div>
          <?php if ($selectedBookId > 0): ?>
            <button class="btn btn2" onclick="openModal('chapter')">+ Chapter</button>
          <?php endif; ?>
        </div>

        <div class="list">
          <?php if (!$books): ?>
            <div class="emptyState">No books yet. Create one using <b>+ Book</b>.</div>
          <?php endif; ?>

          <?php foreach ($books as $b): ?>
            <a class="item" href="?book=<?= (int) $b['id'] ?>">
              <div class="t"><?= h($b['title']) ?></div>
              <div class="s"><?= ((int) $b['id'] === $selectedBookId) ? 'Selected' : 'Open' ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <div class="row" style="justify-content:space-between;margin-bottom:8px">
          <div class="pill">Chapters</div>
          <?php if ($selectedBookId > 0): ?>
            <span class="muted">Book #<?= $selectedBookId ?></span>
          <?php endif; ?>
        </div>

        <div class="list">
          <?php if ($selectedBookId <= 0): ?>
            <div class="emptyState">Select a book to view chapters.</div>
          <?php elseif (!$chapters): ?>
            <div class="emptyState">No chapters yet. Create one using <b>+ Chapter</b>.</div>
          <?php else: ?>
            <?php foreach ($chapters as $c): ?>
              <a class="item" href="?book=<?= (int) $selectedBookId ?>&chapter=<?= (int) $c['id'] ?>">
                <div class="t"><?= h($c['title']) ?></div>
                <div class="s">
                  <?= ((int) $c['id'] === $selectedChapterId) ? 'Editing' : 'Open' ?>
                  <?php if (!empty($c['last_saved_at'])): ?>
                    &middot; last saved <?= h((string) $c['last_saved_at']) ?>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="hdr">
      <h3><?= $topic ? 'Topic Editor' : 'Chapter Editor' ?></h3>
      <div class="row">
        <span class="status" id="saveStatus">Ready</span>
        <?php if ($chapter || $topic): ?>
          <button class="btn btn2" onclick="manualSave()">Save</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="body">
      <?php if ($topic): ?>
        <div class="split">
          <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div class="pill">Editing Topic #<?= (int) $topic['id'] ?></div>
            <?php if ($selectedChapterId > 0): ?>
              <a class="btn btn2" href="?book=<?= (int) $selectedBookId ?>&chapter=<?= (int) $selectedChapterId ?>">Back to Chapter</a>
            <?php endif; ?>
          </div>
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Topic Name</div>
            <input id="topicNameField" class="field" value="<?= h((string) $topic['name']) ?>" placeholder="Topic name">
          </div>
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Description</div>
            <textarea id="topicDescriptionField" class="field" placeholder="Short description"><?= h((string) ($topic['description'] ?? '')) ?></textarea>
          </div>
          <div class="editorWrap">
            <div class="topbar">
              <div>
                <p class="titleBig">Full Write-up</p>
                <div class="muted" style="font-size:12px">
                  Rich editor for the full topic content.
                </div>
              </div>
              <div class="row">
                <span class="pill">Autosave: 60s</span>
              </div>
            </div>
            <div id="editor"></div>
          </div>
        </div>
      <?php elseif ($chapter): ?>
        <div class="split">
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Chapter Name</div>
            <input id="chapterTitleField" class="field" value="<?= h((string) $chapter['title']) ?>" placeholder="Chapter name">
          </div>
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Description</div>
            <textarea id="chapterDescriptionField" class="field" placeholder="Short chapter description"><?= h((string) ($chapter['description'] ?? '')) ?></textarea>
          </div>
          <div>
            <div class="row" style="justify-content:space-between;margin-bottom:8px">
              <div class="pill">Linked Topics</div>
              <span class="muted"><?= count($linkedTopics) ?> linked</span>
            </div>
            <?php if (!$linkedTopics): ?>
              <div class="emptyState">Link topics from the right panel. A chapter can link to many topics.</div>
            <?php else: ?>
              <div class="list">
                <?php foreach ($linkedTopics as $linkedTopic): ?>
                  <a class="item" href="?book=<?= (int) $selectedBookId ?>&chapter=<?= (int) $selectedChapterId ?>&topic=<?= (int) $linkedTopic['id'] ?>">
                    <div class="t"><?= h((string) $linkedTopic['name']) ?></div>
                    <div class="s">Open topic editor</div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="editorWrap">
            <div class="topbar">
              <div>
                <p class="titleBig">Chapter Detail</p>
                <div class="muted" style="font-size:12px">
                  Book #<?= (int) $chapter['book_id'] ?> &middot; Chapter #<?= (int) $chapter['id'] ?>
                  <?php if (!empty($chapter['last_saved_at'])): ?>
                    &middot; last saved <?= h((string) $chapter['last_saved_at']) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="row">
                <span class="pill">Autosave: 60s</span>
              </div>
            </div>
            <div id="editor"></div>
          </div>
        </div>
      <?php else: ?>
        <div class="emptyState">
          Select a chapter to edit its details, or open a topic to edit its full write-up.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="hdr">
      <h3>Topics</h3>
      <?php if ($selectedBookId > 0): ?>
        <button class="btn" onclick="openModal('topic')">+ Topic</button>
      <?php endif; ?>
    </div>
    <div class="body">
      <?php if ($selectedBookId <= 0): ?>
        <div class="emptyState">Select a book to view and create topics.</div>
      <?php else: ?>
        <div class="muted" style="margin-bottom:10px">
          Topics belong to the book and can be linked to multiple chapters.
        </div>

        <div class="list" id="topicList">
          <?php if (!$topics): ?>
            <div class="emptyState">No topics yet. Add one using <b>+ Topic</b>.</div>
          <?php else: ?>
            <?php foreach ($topics as $t): ?>
              <div class="item" data-topic-id="<?= (int) $t['id'] ?>">
                <div class="row" style="justify-content:space-between;gap:10px;align-items:flex-start">
                  <div style="flex:1">
                    <a class="t" href="?book=<?= (int) $selectedBookId ?><?= $selectedChapterId > 0 ? '&chapter=' . (int) $selectedChapterId : '' ?>&topic=<?= (int) $t['id'] ?>"><?= h($t['name']) ?></a>
                    <div class="s">
                      <?php if ($selectedChapterId <= 0): ?>
                        Select a chapter to link this topic
                      <?php elseif (!empty((int) $t['linked_to_selected'])): ?>
                        Linked to selected chapter
                      <?php else: ?>
                        Not linked to selected chapter
                      <?php endif; ?>
                    </div>
                  </div>
                  <button class="btn btnDanger" style="padding:6px 9px" onclick="deleteTopic(<?= (int) $t['id'] ?>)">Del</button>
                </div>
                <?php if (!empty($t['description'])): ?>
                  <div class="s"><?= nl2br(h($t['description'])) ?></div>
                <?php else: ?>
                  <div class="s muted">No description</div>
                <?php endif; ?>
                <div class="row" style="margin-top:8px;justify-content:space-between">
                  <a class="btn btn2" href="?book=<?= (int) $selectedBookId ?><?= $selectedChapterId > 0 ? '&chapter=' . (int) $selectedChapterId : '' ?>&topic=<?= (int) $t['id'] ?>">Open</a>
                  <?php if ($selectedChapterId > 0): ?>
                    <?php if (!empty((int) $t['linked_to_selected'])): ?>
                      <button class="btn btn2" onclick="unlinkTopic(<?= (int) $t['id'] ?>)">Unlink</button>
                    <?php else: ?>
                      <button class="btn" onclick="linkTopic(<?= (int) $t['id'] ?>)">Link to Chapter</button>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modalBg" id="modalBg" onclick="if(event.target.id==='modalBg'){closeModal()}">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="hdr">
      <h3 id="modalTitle">Modal</h3>
      <button class="btn btn2" onclick="closeModal()">Close</button>
    </div>
    <div class="body">
      <div id="modalBody"></div>
    </div>
  </div>
</div>
