-- ============================================================
-- seed.sql (updated to match schema.sql WITHOUT changing schema)
-- Includes staging_workforce table creation
-- ============================================================

-- Use the same DB name as schema.sql
CREATE DATABASE IF NOT EXISTS Workforce_Dashboard;
USE Workforce_Dashboard;

-- ============================================================
-- 0) Create staging table (raw strings for clean ingestion)
-- ============================================================
CREATE TABLE IF NOT EXISTS staging_workforce (
  employee_id_raw           VARCHAR(50),
  first_name_raw            VARCHAR(255),
  last_name_raw             VARCHAR(255),
  job_code_raw              VARCHAR(50),
  title_raw                 VARCHAR(255),
  job_type_raw              VARCHAR(255),
  pay_band_raw              VARCHAR(255),
  tenure_raw                VARCHAR(50),
  anniversary_raw           VARCHAR(50),
  birthday_raw              VARCHAR(50),
  organization_name_raw     VARCHAR(255),
  work_city_raw             VARCHAR(255),
  work_postal_raw           VARCHAR(50),
  state_raw                 VARCHAR(10),
  manager_name_raw          VARCHAR(255),
  manager_id_raw            VARCHAR(50),
  director_name_raw         VARCHAR(255),
  director_id_raw           VARCHAR(50),
  vp_name_raw               VARCHAR(255),
  vp_id_raw                 VARCHAR(50),
  svp_name_raw              VARCHAR(255),
  svp_id_raw                VARCHAR(50)
);

-- ===============================
-- 1) Clear staging
-- ===============================
TRUNCATE TABLE staging_workforce;

-- ===============================
-- 2) Load CSV into staging
-- IMPORTANT: File path must be readable by MariaDB on the PI
-- Put your CSV at: /var/www/dashboard/sql/data.csv
-- ===============================
LOAD DATA INFILE '/var/www/dashboard/sql/data.csv'
INTO TABLE staging_workforce
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
  employee_id_raw,
  first_name_raw,
  last_name_raw,
  job_code_raw,
  title_raw,
  job_type_raw,
  pay_band_raw,
  tenure_raw,
  anniversary_raw,
  birthday_raw,
  organization_name_raw,
  work_city_raw,
  work_postal_raw,
  state_raw,
  manager_name_raw,
  manager_id_raw,
  director_name_raw,
  director_id_raw,
  vp_name_raw,
  vp_id_raw,
  svp_name_raw,
  svp_id_raw
);

-- ============================================================
-- 3) Load lookup tables first (to satisfy foreign keys)
-- ============================================================

-- 3a) ORGANIZATION
-- schema requires organization_id + organization_name
-- CSV provides organization_name, so we generate a stable ID from the name
INSERT INTO `organization` (organization_id, organization_name)
SELECT DISTINCT
  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20) AS organization_id,
  NULLIF(TRIM(organization_name_raw), '') AS organization_name
FROM staging_workforce
WHERE NULLIF(TRIM(organization_name_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  organization_name = VALUES(organization_name);

-- 3b) JOB
-- schema requires job_code + job_type
INSERT INTO job (job_code, job_type)
SELECT DISTINCT
  NULLIF(TRIM(job_code_raw), '') AS job_code,
  NULLIF(TRIM(job_type_raw), '') AS job_type
FROM staging_workforce
WHERE NULLIF(TRIM(job_code_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  job_type = VALUES(job_type);

-- 3c) ROLE (manager/director/vp/svp chain)
-- schema requires ALL 4 ids to be NOT NULL in the role table
-- so only insert rows where all four IDs exist
INSERT INTO `role` (
  role_id,
  manager_id, manager_name,
  director_id, director_name,
  vp_id, vp_name,
  svp_id, svp_name
)
SELECT DISTINCT
  -- role_id isn't used by workforce FKs, but schema requires a PK value
  SUBSTRING(
    MD5(CONCAT_WS('|',
      TRIM(manager_id_raw),
      TRIM(director_id_raw),
      TRIM(vp_id_raw),
      TRIM(svp_id_raw)
    )),
    1, 20
  ) AS role_id,

  NULLIF(TRIM(manager_id_raw), '') AS manager_id,
  NULLIF(TRIM(manager_name_raw), '') AS manager_name,

  NULLIF(TRIM(director_id_raw), '') AS director_id,
  NULLIF(TRIM(director_name_raw), '') AS director_name,

  NULLIF(TRIM(vp_id_raw), '') AS vp_id,
  NULLIF(TRIM(vp_name_raw), '') AS vp_name,

  NULLIF(TRIM(svp_id_raw), '') AS svp_id,
  NULLIF(TRIM(svp_name_raw), '') AS svp_name

FROM staging_workforce
WHERE NULLIF(TRIM(manager_id_raw), '') IS NOT NULL
  AND NULLIF(TRIM(director_id_raw), '') IS NOT NULL
  AND NULLIF(TRIM(vp_id_raw), '') IS NOT NULL
  AND NULLIF(TRIM(svp_id_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  manager_name  = VALUES(manager_name),
  director_name = VALUES(director_name),
  vp_name       = VALUES(vp_name),
  svp_name      = VALUES(svp_name);

-- ============================================================
-- 4) Upsert into WORKFORCE (matches schema columns exactly)
-- ============================================================
INSERT INTO workforce (
  employee_id,
  first_name,
  last_name,
  job_code,
  pay_band,
  tenure,
  anniversary,
  birthday,
  work_city,
  state,
  work_postal,
  organization_id,
  manager_id,
  director_id,
  vp_id,
  svp_id
)
SELECT
  NULLIF(TRIM(employee_id_raw), '') AS employee_id,

  NULLIF(TRIM(first_name_raw), '') AS first_name,
  NULLIF(TRIM(last_name_raw), '')  AS last_name,

  NULLIF(TRIM(job_code_raw), '')   AS job_code,
  NULLIF(TRIM(pay_band_raw), '')   AS pay_band,

  CAST(NULLIF(TRIM(tenure_raw), '') AS UNSIGNED) AS tenure,

  -- Accept YYYY-MM-DD or MM/DD/YYYY
  COALESCE(
    STR_TO_DATE(NULLIF(TRIM(anniversary_raw), ''), '%Y-%m-%d'),
    STR_TO_DATE(NULLIF(TRIM(anniversary_raw), ''), '%m/%d/%Y')
  ) AS anniversary,

  COALESCE(
    STR_TO_DATE(NULLIF(TRIM(birthday_raw), ''), '%Y-%m-%d'),
    STR_TO_DATE(NULLIF(TRIM(birthday_raw), ''), '%m/%d/%Y')
  ) AS birthday,

  NULLIF(TRIM(work_city_raw), '')   AS work_city,
  NULLIF(TRIM(state_raw), '')       AS state,
  NULLIF(TRIM(work_postal_raw), '') AS work_postal,

  -- organization_name -> organization_id (generated)
  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20) AS organization_id,

  NULLIF(TRIM(manager_id_raw), '')  AS manager_id,
  NULLIF(TRIM(director_id_raw), '') AS director_id,
  NULLIF(TRIM(vp_id_raw), '')       AS vp_id,
  NULLIF(TRIM(svp_id_raw), '')      AS svp_id

FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  first_name      = VALUES(first_name),
  last_name       = VALUES(last_name),
  job_code        = VALUES(job_code),
  pay_band        = VALUES(pay_band),
  tenure          = VALUES(tenure),
  anniversary     = VALUES(anniversary),
  birthday        = VALUES(birthday),
  work_city       = VALUES(work_city),
  state           = VALUES(state),
  work_postal     = VALUES(work_postal),
  organization_id = VALUES(organization_id),
  manager_id      = VALUES(manager_id),
  director_id     = VALUES(director_id),
  vp_id           = VALUES(vp_id),
  svp_id          = VALUES(svp_id);

-- ============================================================
-- 5) Optional validation queries (super useful for debugging)
-- ============================================================

-- A) Employees present in staging but missing from workforce
SELECT s.employee_id_raw AS missing_employee_id
FROM staging_workforce s
LEFT JOIN workforce w
  ON w.employee_id = TRIM(s.employee_id_raw)
WHERE NULLIF(TRIM(s.employee_id_raw), '') IS NOT NULL
  AND w.employee_id IS NULL;

-- B) Rows that likely failed due to missing FK targets
SELECT
  s.employee_id_raw,
  s.job_code_raw,
  s.organization_name_raw,
  s.manager_id_raw,
  s.director_id_raw,
  s.vp_id_raw,
  s.svp_id_raw
FROM staging_workforce s
LEFT JOIN job j
  ON j.job_code = NULLIF(TRIM(s.job_code_raw), '')
LEFT JOIN `organization` o
  ON o.organization_id = SUBSTRING(MD5(NULLIF(TRIM(s.organization_name_raw), '')), 1, 20)
LEFT JOIN `role` r_m
  ON r_m.manager_id = NULLIF(TRIM(s.manager_id_raw), '')
LEFT JOIN `role` r_d
  ON r_d.director_id = NULLIF(TRIM(s.director_id_raw), '')
LEFT JOIN `role` r_v
  ON r_v.vp_id = NULLIF(TRIM(s.vp_id_raw), '')
LEFT JOIN `role` r_s
  ON r_s.svp_id = NULLIF(TRIM(s.svp_id_raw), '')
WHERE NULLIF(TRIM(s.employee_id_raw), '') IS NOT NULL
  AND (
    (NULLIF(TRIM(s.job_code_raw), '') IS NOT NULL AND j.job_code IS NULL)
    OR (NULLIF(TRIM(s.organization_name_raw), '') IS NOT NULL AND o.organization_id IS NULL)
    OR (NULLIF(TRIM(s.manager_id_raw), '') IS NOT NULL AND r_m.manager_id IS NULL)
    OR (NULLIF(TRIM(s.director_id_raw), '') IS NOT NULL AND r_d.director_id IS NULL)
    OR (NULLIF(TRIM(s.vp_id_raw), '') IS NOT NULL AND r_v.vp_id IS NULL)
    OR (NULLIF(TRIM(s.svp_id_raw), '') IS NOT NULL AND r_s.svp_id IS NULL)
  );
