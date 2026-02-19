-- ============================================================
-- schema.sql (REBUILDABLE / FUTURE-PROOF)
-- ============================================================

CREATE DATABASE IF NOT EXISTS dashboard_prod;
USE dashboard_prod;

-- --------------------------------------------
-- Drop in dependency order (child -> parent)
-- --------------------------------------------
DROP TABLE IF EXISTS login;
DROP TABLE IF EXISTS workforce;

DROP TABLE IF EXISTS manager;
DROP TABLE IF EXISTS director;
DROP TABLE IF EXISTS vp;
DROP TABLE IF EXISTS svp;

DROP TABLE IF EXISTS job;
DROP TABLE IF EXISTS `organization`;

-- --------------------------------------------
-- job
-- --------------------------------------------
CREATE TABLE job (
  job_code VARCHAR(20) PRIMARY KEY,
  job_type VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- --------------------------------------------
-- organization
-- --------------------------------------------
CREATE TABLE `organization` (
  organization_id VARCHAR(20) PRIMARY KEY,
  organization_name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- --------------------------------------------
-- hierarchy lookup tables
-- (these store the people at each level)
-- --------------------------------------------
CREATE TABLE manager (
  manager_id VARCHAR(20) PRIMARY KEY,
  manager_name VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE director (
  director_id VARCHAR(20) PRIMARY KEY,
  director_name VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE vp (
  vp_id VARCHAR(20) PRIMARY KEY,
  vp_name VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE svp (
  svp_id VARCHAR(20) PRIMARY KEY,
  svp_name VARCHAR(255) NULL
) ENGINE=InnoDB;

-- --------------------------------------------
-- workforce (main fact table)
-- --------------------------------------------
CREATE TABLE workforce (
  employee_id VARCHAR(20) PRIMARY KEY,

  first_name VARCHAR(255) NOT NULL,
  last_name  VARCHAR(255) NOT NULL,

  job_code VARCHAR(20) NULL,
  pay_band VARCHAR(255) NOT NULL,

  tenure INT NULL,
  anniversary DATE NULL,
  birthday DATE NULL,

  work_city   VARCHAR(255) NOT NULL,
  state       CHAR(2) NOT NULL,
  work_postal VARCHAR(10) NOT NULL,

  organization_id VARCHAR(20) NULL,

  manager_id  VARCHAR(20) NULL,
  director_id VARCHAR(20) NULL,
  vp_id       VARCHAR(20) NULL,
  svp_id      VARCHAR(20) NULL,

  CONSTRAINT fk_workforce_job
    FOREIGN KEY (job_code) REFERENCES job(job_code),

  CONSTRAINT fk_workforce_org
    FOREIGN KEY (organization_id) REFERENCES `organization`(organization_id),

  CONSTRAINT fk_workforce_manager
    FOREIGN KEY (manager_id) REFERENCES manager(manager_id),

  CONSTRAINT fk_workforce_director
    FOREIGN KEY (director_id) REFERENCES director(director_id),

  CONSTRAINT fk_workforce_vp
    FOREIGN KEY (vp_id) REFERENCES vp(vp_id),

  CONSTRAINT fk_workforce_svp
    FOREIGN KEY (svp_id) REFERENCES svp(svp_id)
) ENGINE=InnoDB;

-- Useful indexes (joins and filters)
CREATE INDEX idx_workforce_job_code        ON workforce(job_code);
CREATE INDEX idx_workforce_organization_id ON workforce(organization_id);
CREATE INDEX idx_workforce_manager_id      ON workforce(manager_id);
CREATE INDEX idx_workforce_director_id     ON workforce(director_id);
CREATE INDEX idx_workforce_vp_id           ON workforce(vp_id);
CREATE INDEX idx_workforce_svp_id          ON workforce(svp_id);

-- --------------------------------------------
-- login (links to workforce)
-- --------------------------------------------
CREATE TABLE login (
  username VARCHAR(255) PRIMARY KEY,
  `password` VARCHAR(255) NOT NULL,
  employee_id VARCHAR(20) NOT NULL,
  CONSTRAINT fk_login_employee
    FOREIGN KEY (employee_id) REFERENCES workforce(employee_id)
) ENGINE=InnoDB;
