-- ============================================================
-- schema.sql — Workforce Dashboard (Loopback x4 Hierarchy)
-- ============================================================

CREATE DATABASE IF NOT EXISTS dashboard_prod;
USE dashboard_prod;

-- ----------------------------
-- JOB
-- ----------------------------
CREATE TABLE IF NOT EXISTS job (
  job_code VARCHAR(20) PRIMARY KEY,
  job_type VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ----------------------------
-- ORGANIZATION
-- ----------------------------
CREATE TABLE IF NOT EXISTS organization (
  organization_id VARCHAR(20) PRIMARY KEY,
  organization_name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ----------------------------
-- WORKFORCE (self-referencing hierarchy)
-- ----------------------------
CREATE TABLE IF NOT EXISTS workforce (
  employee_id VARCHAR(20) PRIMARY KEY,

  first_name VARCHAR(255) NOT NULL,
  last_name  VARCHAR(255) NOT NULL,

  job_code VARCHAR(20),
  pay_band VARCHAR(255) NOT NULL,

  tenure INT NOT NULL,

  anniversary DATE NULL,
  birthday    DATE NULL,

  work_city   VARCHAR(255) NOT NULL,
  state       CHAR(2) NOT NULL,
  work_postal VARCHAR(10) NOT NULL,

  organization_id VARCHAR(20),

  -- Role from CSV (Employee / Manager / Director / VP / SVP etc.)
  role VARCHAR(50) NOT NULL,

  -- Loopbacks (self FKs)
  manager_id  VARCHAR(20) NULL,
  director_id VARCHAR(20) NULL,
  vp_id       VARCHAR(20) NULL,
  svp_id      VARCHAR(20) NULL,

  -- ---- Foreign keys ----
  CONSTRAINT fk_workforce_job
    FOREIGN KEY (job_code)
    REFERENCES job(job_code)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_org
    FOREIGN KEY (organization_id)
    REFERENCES organization(organization_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_manager
    FOREIGN KEY (manager_id)
    REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_director
    FOREIGN KEY (director_id)
    REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_vp
    FOREIGN KEY (vp_id)
    REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,

  CONSTRAINT fk_workforce_svp
    FOREIGN KEY (svp_id)
    REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------
-- LOGIN
-- ----------------------------
CREATE TABLE IF NOT EXISTS login (
  username VARCHAR(255) PRIMARY KEY,
  password VARCHAR(255) NOT NULL,
  employee_id VARCHAR(20) NOT NULL,

  CONSTRAINT fk_login_employee
    FOREIGN KEY (employee_id)
    REFERENCES workforce(employee_id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB;
