-- ProjectDesk schema (MySQL 5.7+/8, MariaDB 10.3+). Tables use the pd_ prefix.
CREATE TABLE IF NOT EXISTS pd_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('Admin','User') NOT NULL DEFAULT 'User',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pd_projects (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  goal        TEXT NULL,
  owner_id    INT UNSIGNED NULL,
  start_date  DATE NOT NULL,
  target_date DATE NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pd_proj_owner FOREIGN KEY (owner_id) REFERENCES pd_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pd_tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NOT NULL,
  name         VARCHAR(200) NOT NULL,
  owner_id     INT UNSIGNED NULL,
  target_date  DATE NOT NULL,
  effort       DECIMAL(7,1) NOT NULL DEFAULT 0,
  status       ENUM('Not Started','In Progress','On Hold','Completed') NOT NULL DEFAULT 'Not Started',
  remark       TEXT NULL,
  completed_at DATETIME NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pd_task_owner (owner_id, status),
  KEY idx_pd_task_target (target_date),
  CONSTRAINT fk_pd_task_proj  FOREIGN KEY (project_id) REFERENCES pd_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_task_owner FOREIGN KEY (owner_id)   REFERENCES pd_users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
