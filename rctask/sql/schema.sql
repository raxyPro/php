-- rcphp schema (MySQL 8+ / MariaDB 10.4+)
-- Run once:  mysql -u root -p < sql/schema.sql

CREATE DATABASE IF NOT EXISTS rcfamily CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rcfamily;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email          VARCHAR(190) NOT NULL UNIQUE,
  name           VARCHAR(100) NOT NULL DEFAULT '',
  password_hash  VARCHAR(255) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A slot of the week when a task can realistically be done
CREATE TABLE IF NOT EXISTS bandwidths (
  user_id         INT UNSIGNED NOT NULL,
  id              VARCHAR(40) NOT NULL,
  name            VARCHAR(80) NOT NULL,
  description     VARCHAR(400) NOT NULL DEFAULT '',
  hours_per_week  DECIMAL(5,2) NOT NULL DEFAULT 0,
  hue             VARCHAR(12) NOT NULL DEFAULT 'slate',
  days            VARCHAR(20) NOT NULL DEFAULT '',   -- CSV of 0..6, 0 = Sunday
  start_time      TIME NULL,
  end_time        TIME NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, id),
  CONSTRAINT fk_bw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks now; events and expenses later share this table
CREATE TABLE IF NOT EXISTS entries (
  id            CHAR(36) NOT NULL PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  kind          ENUM('task','event','expense') NOT NULL,
  event_type    ENUM('event','planned_event') NULL,
  title         VARCHAR(300) NOT NULL,
  bandwidth_id  VARCHAR(40) NULL,
  status        ENUM('To do','In progress','Waiting','Done') NOT NULL DEFAULT 'To do',
  priority      ENUM('High','Medium','Low') NOT NULL DEFAULT 'Medium',
  due_date      DATE NULL,
  event_date    DATE NULL,
  planned_due   DATE NULL,
  effort_min    INT NULL,
  amount        DECIMAL(12,2) NULL,
  pay_mode      VARCHAR(30) NULL,
  category      VARCHAR(40) NULL,
  person        VARCHAR(80) NULL,
  remark        VARCHAR(500) NULL,
  notes_html    MEDIUMTEXT NULL,
  why           VARCHAR(200) NULL,
  source_text   TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at  DATETIME NULL,
  KEY idx_user_kind (user_id, kind, status),
  KEY idx_user_due (user_id, due_date),
  CONSTRAINT fk_entry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
