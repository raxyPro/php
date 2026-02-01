ChatGpt Prompt
----------------
create a dynamic  event recording App 

the stucture
folder-> event date, event name, event desc, event remark, event files URL, event tag (either choose from existing tags or create new tag-make this feature user friendly)
the folder is a name and always link to a physical directory
use sqllite DB and store data in same folder 
this way i will be able to store data locally

objective
record the event easily and add multiple file URL

use interface my desire
left side 
folder list
tag list


life cycle of events
create new folder, ask for name and folder URL
two way to add event
1. add event and add files URL
2. the files are always listed on right and then allow files to be dragged on event and file URL will be automatically added

Codex Prompt
You are a senior desktop app engineer. Build a local-first “Event Recording App” with a clean UI and SQLite storage per folder.

TECH STACK
- Python 3.11+
- PyQt6 (UI)
- SQLite (built-in)
- No cloud. Everything stored locally.
- Each “Folder” maps 1:1 to a physical directory on disk.
- The SQLite DB file must live INSIDE that physical directory (e.g., <folder_path>/.event_app/events.db)

CORE DATA MODEL
1) Folder (a logical workspace)
- id (uuid or autoincrement)
- name (string, required)
- path (string, required, absolute path to an existing directory)
- created_at

2) Tag
- id
- name (unique, case-insensitive)
- color (optional)
- created_at

3) Event
- id
- folder_id (fk)
- event_date (date/time)
- name (string, required)
- description (text)
- remark (text)
- created_at, updated_at

4) EventFile (multiple per event)
- id
- event_id (fk)
- file_url (string, required)
- file_type (optional: “local_file”, “http”, “other”)
- added_at
- display_name (optional)

5) EventTag (many-to-many)
- event_id, tag_id

IMPORTANT STORAGE RULES
- A “Folder” is stored in a central registry DB in the app root (or user home) that tracks folder name + path.
- BUT event data (Events, Tags, mapping, EventFiles) must be stored in the folder’s own SQLite db located inside that folder path.
- When user selects a folder, load that folder’s db and show its events/tags/files.

UI / UX (MANDATORY)
Main window with a 3-pane layout:

LEFT SIDEBAR (vertical split or tabs)
A) Folder List
- Shows all registered folders
- Buttons: “+ New Folder”, “Remove Folder”, “Open Folder Path”
- “+ New Folder” opens a dialog:
  - Folder Name
  - Folder Path chooser (must exist; user can browse)
  - On save: create folder db if not exists; register in central registry db.

B) Tag List
- Shows tags for the currently selected folder
- Search box
- “+ Tag” inline creation (user-friendly):
  - In event form tag selector, allow typing to search existing tags OR type new tag name and press Enter to create.
- Clicking a tag filters the event list.

CENTER PANE (Event List + controls)
- Top controls:
  - “+ Add Event”
  - Search box (search event name/desc/remark)
  - Date filter (optional)
- Event list/table columns:
  - Date, Name, Tags, #Files
- Selecting an event shows details in the RIGHT pane.

RIGHT PANE (Files Panel + Event Details)
Top: “Files Inbox” panel
- Always shows files detected/added for the selected folder:
  - Provide an “Add Files…” button to pick local files.
  - Also allow drag & drop local files from OS into this panel.
- Each file item shows name + full path (or URL), and icon if possible.
- This panel is the source for drag-to-event.

Below: Event Details panel
- Fields: event_date (datetime picker), name, description, remark
- Tag selector:
  - Multi-select chips
  - Typeahead; can create new tag from the same field (user-friendly)
- Event Files list:
  - Shows all file URLs already linked to this event
  - Buttons: “Add URL”, “Remove”
  - Double-click opens local file or URL (use QDesktopServices)

EVENT LIFECYCLE REQUIREMENTS
1) Create new folder:
- Ask for name and folder URL/path
- Create per-folder DB inside that directory if missing.

2) Add event (two ways)
Way #1 (form-first):
- User clicks “+ Add Event”
- Fill event details
- Add multiple file URLs via:
  - “Add URL” button (paste URL or browse local file -> converts to file:// URL or stores local path string)
  - Also allow dropping files onto the Event Details file area to auto-add URLs.

Way #2 (files-first then drag to event):
- Files are always listed on the right in “Files Inbox”
- User drags a file from Files Inbox onto an event in the event list
- On drop: automatically create an EventFile row linking that file URL to that event.
- If event is not yet created, allow dropping onto empty area to prompt “Create new event with these files?” (nice-to-have)

DRAG & DROP (MUST)
- Implement drag source: file items in Files Inbox (use QMimeData with text/urls).
- Implement drop targets:
  - Event rows in the event list
  - Event Details “linked files” area
- On drop: add to EventFile for that event; avoid duplicates.

DATABASE / MIGRATIONS
- On first open of a folder db: create schema tables if not exist.
- Use foreign keys, indexes for performance.
- Ensure tag uniqueness case-insensitive (enforce in code; optionally in db with a normalized column).

QUALITY REQUIREMENTS
- Provide complete runnable code with this structure:

event_app/
  app.py
  requirements.txt
  core/
    models.py
    db_registry.py
    db_folder.py
    repository.py
    utils.py
  ui/
    main_window.py
    dialogs.py
    widgets.py
  assets/ (optional)

- app.py launches the PyQt6 app.
- Clean separation: UI <-> repository <-> db.
- Error handling: invalid folder path, db open failure, duplicate tag, etc.
- Make UI responsive and user-friendly (icons optional but layout must be clean).
- Add small status bar messages on actions (file added, event saved, etc).

DELIVERABLE
- Output ALL code files with headings like:
  ### event_app/app.py
  ```python
  ...
additional prompt

OS-NEUTRAL REQUIREMENTS (add this section to the prompt)

- Must run on Windows, macOS, and Linux without code changes.
- Use pathlib.Path everywhere (no hardcoded “C:\”, no manual “/” joins).
- Store folder paths and local file paths in a canonical, absolute form using Path.resolve().
- When saving “file URLs”:
  - For local files, store BOTH:
    1) local_path (absolute path string from Path.resolve())
    2) file_url (generated via Path.as_uri())
  - For web links, store only file_url and leave local_path NULL.
- Never assume drive letters; do not parse paths by string splitting—always use pathlib.
- Opening files/URLs must use Qt cross-platform APIs:
  - Use QDesktopServices.openUrl(QUrl(...)) for both local file URLs and http(s) URLs.
- File dialogs must be Qt-native and OS-neutral:
  - Use QFileDialog.getExistingDirectory for folder selection
  - Use QFileDialog.getOpenFileNames for multi-file selection
- Drag & drop must use QMimeData.urls() (QUrl list) so OS provides correct paths.
  - Convert dropped QUrls to local paths via url.toLocalFile() and then Path(...).resolve()
- The per-folder SQLite DB location must be created with Path operations:
  - db_path = folder_path / ".event_app" / "events.db"
  - Ensure parent exists with mkdir(parents=True, exist_ok=True)
- Respect OS file permissions:
  - Gracefully handle read-only folders (show UI error + do not crash).
- Never rely on OS-specific commands (no `start`, `open`, `xdg-open`, etc.).
- Add a utility function normalize_local_path(p: str|Path) -> Path that:
  - expands user (~), resolves symlinks where possible, and returns absolute Path.
- All tests / assumptions should work even if the selected folder is on removable media or network mounts.
