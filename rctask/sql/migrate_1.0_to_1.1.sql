-- rcexe: upgrade an existing 1.0 database to 1.1. Run ONCE per database
-- (local rctask and production), after taking a backup (phpMyAdmin -> Export).
--   mysql -u root -p rctask < sql/migrate_1.0_to_1.1.sql
-- Existing tasks are kept. Status mapping:
--   To do -> New, In progress -> Progress, Waiting -> Progress, Done -> Completed
-- Old "Notes" become the task's "Remark".
 
-- 1. Missions
CREATE TABLE IF NOT EXISTS missions (
  id            CHAR(36) NOT NULL PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  title         VARCHAR(200) NOT NULL,
  goal          VARCHAR(1000) NULL,
  target_date   DATE NULL,
  status        ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
  hue           VARCHAR(12) NOT NULL DEFAULT 'blue',
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at  DATETIME NULL,
  KEY idx_mission_user (user_id, status),
  CONSTRAINT fk_mission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. New columns and the Idea entry type
ALTER TABLE entries
  MODIFY kind ENUM('task','idea','event','expense') NOT NULL,
  ADD COLUMN mission_id    CHAR(36) NULL AFTER event_type,
  ADD COLUMN due_time      TIME NULL AFTER due_date,
  ADD COLUMN progress_html MEDIUMTEXT NULL AFTER remark,
  ADD COLUMN remark_html   MEDIUMTEXT NULL AFTER progress_html,
  ADD KEY idx_mission (mission_id),
  ADD CONSTRAINT fk_entry_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE SET NULL;

-- 3. Statuses: allow old + new, map, then keep only new
ALTER TABLE entries MODIFY status ENUM('To do','In progress','Waiting','Done','New','Progress','Completed','Cancelled') NOT NULL DEFAULT 'New';
UPDATE entries SET status = 'New'       WHERE status = 'To do';
UPDATE entries SET status = 'Progress'  WHERE status IN ('In progress','Waiting');
UPDATE entries SET status = 'Completed' WHERE status = 'Done';
ALTER TABLE entries MODIFY status ENUM('New','Progress','Completed','Cancelled') NOT NULL DEFAULT 'New';

-- 4. Old task notes become the Remark
UPDATE entries SET remark_html = notes_html, notes_html = NULL
 WHERE kind = 'task' AND notes_html IS NOT NULL AND remark_html IS NULL;
