-- ============================================================
-- schema.sql — Workforce Dashboard (Loopback x4 Hierarchy)
-- ============================================================

DROP DATABASE IF EXISTS dashboard_prod;
CREATE DATABASE dashboard_prod;
USE dashboard_prod;

CREATE TABLE job (
  job_code VARCHAR(20) PRIMARY KEY,
  job_type VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE organization (
  organization_id VARCHAR(20) PRIMARY KEY,
  organization_name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE workforce (
  employee_id VARCHAR(20) PRIMARY KEY,

  first_name VARCHAR(255) NOT NULL,
  last_name  VARCHAR(255) NOT NULL,

  job_code VARCHAR(20) NULL,
  title    VARCHAR(255) NULL,          -- ✅ add this
  pay_band VARCHAR(255) NOT NULL,
  tenure   INT NULL,

  anniversary DATE NULL,
  birthday    DATE NULL,

  work_city   VARCHAR(255) NOT NULL,
  state       CHAR(2) NOT NULL,
  work_postal VARCHAR(10) NOT NULL,

  organization_id VARCHAR(20) NULL,
  role VARCHAR(50) NULL,

  manager_id  VARCHAR(20) NULL,
  director_id VARCHAR(20) NULL,
  vp_id       VARCHAR(20) NULL,
  svp_id      VARCHAR(20) NULL,

  CONSTRAINT fk_workforce_job
    FOREIGN KEY (job_code) REFERENCES job(job_code)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_workforce_org
    FOREIGN KEY (organization_id) REFERENCES organization(organization_id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_workforce_manager
    FOREIGN KEY (manager_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_workforce_director
    FOREIGN KEY (director_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_workforce_vp
    FOREIGN KEY (vp_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_workforce_svp
    FOREIGN KEY (svp_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login (
  username VARCHAR(255) PRIMARY KEY,
  password VARCHAR(255) NOT NULL,
  employee_id VARCHAR(20) NOT NULL,
  CONSTRAINT fk_login_employee
    FOREIGN KEY (employee_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;
