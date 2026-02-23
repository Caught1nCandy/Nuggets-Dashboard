-- ============================================================
-- schema.sql — Workforce Dashboard
-- ERD: job → workforce → organization
--               ↕ (self-referencing hierarchy)
--      workforce → location
--      workforce → login
-- ============================================================

DROP DATABASE IF EXISTS dashboard_prod;
CREATE DATABASE dashboard_prod;
USE dashboard_prod;

-- ------------------------------------------------------------
-- 1. JOB
--    Stores all job classification data. Title and pay_band
--    belong here — not on the employee — because they are
--    properties of the job code, not the individual.
-- ------------------------------------------------------------
CREATE TABLE job (
  job_code  VARCHAR(20)  NOT NULL,
  title     VARCHAR(255) NOT NULL,
  job_type  VARCHAR(50)  NOT NULL,   -- Exempt | Management | Support Hourly
  pay_band  VARCHAR(10)  NOT NULL,   -- TI, TL, DC, T2, T4, T5, T6, TE, TJ, TK
  PRIMARY KEY (job_code)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. ORGANIZATION
--    Department / business unit names.
--    org_id is auto-generated (no natural key in source data).
-- ------------------------------------------------------------
CREATE TABLE organization (
  org_id            INT          NOT NULL AUTO_INCREMENT,
  organization_name VARCHAR(255) NOT NULL UNIQUE,
  PRIMARY KEY (org_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. LOCATION
--    Physical work locations. Normalized out of workforce to
--    avoid repeating city/state/postal across thousands of rows.
-- ------------------------------------------------------------
CREATE TABLE location (
  location_id  INT         NOT NULL AUTO_INCREMENT,
  work_city    VARCHAR(255) NOT NULL,
  state        CHAR(2)      NOT NULL,
  work_postal  VARCHAR(10)  NOT NULL,
  PRIMARY KEY (location_id),
  UNIQUE KEY uq_location (work_city, state, work_postal)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. WORKFORCE (core employee table)
--    Self-referencing x4 for the org hierarchy chain:
--    employee → manager → director → vp → svp
--    All four are FKs back to this same table.
-- ------------------------------------------------------------
CREATE TABLE workforce (
  employee_id  VARCHAR(20)  NOT NULL,
  first_name   VARCHAR(255) NOT NULL,
  last_name    VARCHAR(255) NOT NULL,
  tenure       INT          NULL,
  anniversary  DATE         NULL,
  birthday     DATE         NULL,
  role         VARCHAR(50)  NULL,       -- Employee | Manager | Director | VP | SVP
  job_code     VARCHAR(20)  NULL,       -- FK → job
  org_id       INT          NULL,       -- FK → organization
  location_id  INT          NULL,       -- FK → location
  manager_id   VARCHAR(20)  NULL,       -- FK → workforce (self)
  director_id  VARCHAR(20)  NULL,       -- FK → workforce (self)
  vp_id        VARCHAR(20)  NULL,       -- FK → workforce (self)
  svp_id       VARCHAR(20)  NULL,       -- FK → workforce (self)
  PRIMARY KEY (employee_id),
  CONSTRAINT fk_workforce_job
    FOREIGN KEY (job_code)    REFERENCES job(job_code)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_workforce_org
    FOREIGN KEY (org_id)      REFERENCES organization(org_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_workforce_location
    FOREIGN KEY (location_id) REFERENCES location(location_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_workforce_manager
    FOREIGN KEY (manager_id)  REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_workforce_director
    FOREIGN KEY (director_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_workforce_vp
    FOREIGN KEY (vp_id)       REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_workforce_svp
    FOREIGN KEY (svp_id)      REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. LOGIN
--    One login record per employee.
--    Username = employee_id (as discussed).
--    Password is a VARCHAR to store a hashed value generated
--    during the ETL/seed process (e.g. bcrypt or SHA2).
--    login_id is a surrogate PK so usernames can safely change.
-- ------------------------------------------------------------
CREATE TABLE login (
  login_id    INT          NOT NULL AUTO_INCREMENT,
  username    VARCHAR(20)  NOT NULL UNIQUE,  -- mirrors employee_id value
  password    VARCHAR(255) NOT NULL,         -- store hashed, never plain text
  employee_id VARCHAR(20)  NOT NULL UNIQUE,  -- one login per employee
  PRIMARY KEY (login_id),
  CONSTRAINT fk_login_employee
    FOREIGN KEY (employee_id) REFERENCES workforce(employee_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;
