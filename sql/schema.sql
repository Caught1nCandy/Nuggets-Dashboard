-- ============================================================
-- schema.sql — Workforce Dashboard (Loopback x4 Hierarchy)
-- Matches CSV columns + seed pipeline (staging -> lookups -> workforce)
-- ============================================================

CREATE DATABASE IF NOT EXISTS dashboard_prod
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_uca1400_ai_ci;

USE dashboard_prod;

-- ------------------------------------------------------------
-- job lookup
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job (
  job_code VARCHAR(20) NOT NULL,
  job_type VARCHAR(255) NOT NULL,
  CONSTRAINT pk_job PRIMARY KEY (job_code)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci;

-- ------------------------------------------------------------
-- organization lookup
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organization (
  organization_id   VARCHAR(20)  NOT NULL,
  organization_name VARCHAR(255) NOT NULL,
  CONSTRAINT pk_organization PRIMARY KEY (organization_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci;

-- ------------------------------------------------------------
-- workforce (central table + self-referential hierarchy)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workforce (
  employee_id VARCHAR(20)  NOT NULL,

  first_name  VARCHAR(255) NOT NULL,
  last_name   VARCHAR(255) NOT NULL,

  job_code    VARCHAR(20)  NULL,

  -- From CSV
  title       VARCHAR(255) NULL,      -- ✅ added (was missing)
  role        VARCHAR(50)  NOT NULL,  -- Employee / Manager / Director etc.

  pay_band    VARCHAR(255) NOT NULL,
  tenure      INT          NULL,      -- allow NULL if parsing fails or missing

  anniversary DATE NULL,
  birthday    DATE NULL,

  work_city   VARCHAR(255) NOT NULL,
  state       CHAR(2)      NOT NULL,
  work_postal VARCHAR(10)  NOT NULL,

  organization_id VARCHAR(20) NULL,

  -- Loopback pointers (employee_id of the person in that position)
  manager_id  VARCHAR(20) NULL,
  director_id VARCHAR(20) NULL,
  vp_id       VARCHAR(20) NULL,
  svp_id      VARCHAR(20) NULL,

  CONSTRAINT pk_workforce PRIMARY KEY (employee_id),

  -- helpful indexes for hierarchy queries
  INDEX idx_workforce_job (job_code),
  INDEX idx_workforce_org (organization_id),
  INDEX idx_workforce_manager (manager_id),
  INDEX idx_workforce_director (director_id),
  INDEX idx_workforce_vp (vp_id),
  INDEX idx_workforce_svp (svp_id),

  -- foreign keys
  CONSTRAINT fk_workforce_job
    FOREIGN KEY (job_code) REFERENCES job(job_code)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_org
    FOREIGN KEY (organization_id) REFERENCES organization(organization_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_manager
    FOREIGN KEY (manager_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_director
    FOREIGN KEY (director_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_vp
    FOREIGN KEY (vp_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_svp
    FOREIGN KEY (svp_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci;

-- ------------------------------------------------------------
-- login table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login (
  username    VARCHAR(255) NOT NULL,
  `password`  VARCHAR(255) NOT NULL,
  employee_id VARCHAR(20)  NOT NULL,
  CONSTRAINT pk_login PRIMARY KEY (username),
  INDEX idx_login_employee (employee_id),
  CONSTRAINT fk_login_employee
    FOREIGN KEY (employee_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci;
