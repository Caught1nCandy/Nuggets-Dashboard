-- ============================================================
-- seed.sql (STAGING -> LOOKUPS -> WORKFORCE)
-- Compatible with the new schema.sql above
-- ============================================================

USE dashboard_prod;

-- ============================================================
-- 0) staging table (raw CSV strings)
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
) ENGINE=InnoDB;

-- ============================================================
-- 1) Clear staging
-- ============================================================
TRUNCATE TABLE staging_workforce;

-- ============================================================
-- 2) Load CSV into staging
-- If LOCAL fails in your environment, remove LOCAL and ensure
-- MariaDB server can read the file path.
-- ============================================================
LOAD DATA LOCAL INFILE '/var/www/dashboard/sql/data.csv'
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
-- Helper pattern used below:
-- "blank id" = NULL if empty OR all zeros
-- ============================================================

-- ============================================================
-- 3) Load lookup tables
-- ============================================================

-- 3a) organization (stable generated ID from name)
INSERT INTO `organization` (organization_id, organization_name)
SELECT DISTINCT
  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20) AS organization_id,
  NULLIF(TRIM(organization_name_raw), '') AS organization_name
FROM staging_workforce
WHERE NULLIF(TRIM(organization_name_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  organization_name = VALUES(organization_name);

-- 3b) job
INSERT INTO job (job_code, job_type)
SELECT DISTINCT
  NULLIF(TRIM(job_code_raw), '') AS job_code,
  NULLIF(TRIM(job_type_raw), '') AS job_type
FROM staging_workforce
WHERE NULLIF(TRIM(job_code_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  job_type = VALUES(job_type);

-- 3c) manager
INSERT INTO manager (manager_id, manager_name)
SELECT DISTINCT
  TRIM(manager_id_raw) AS manager_id,
  NULLIF(TRIM(manager_name_raw), '') AS manager_name
FROM staging_workforce
WHERE NULLIF(TRIM(manager_id_raw), '') IS NOT NULL
  AND TRIM(manager_id_raw) NOT REGEXP '^0+$'
ON DUPLICATE KEY UPDATE
  manager_name = VALUES(manager_name);

-- 3d) director
INSERT INTO director (director_id, director_name)
SELECT DISTINCT
  TRIM(director_id_raw) AS director_id,
  NULLIF(TRIM(director_name_raw), '') AS director_name
FROM staging_workforce
WHERE NULLIF(TRIM(director_id_raw), '') IS NOT NULL
  AND TRIM(director_id_raw) NOT REGEXP '^0+$'
ON DUPLICATE KEY UPDATE
  director_name = VALUES(director_name);

-- 3e) vp
INSERT INTO vp (vp_id, vp_name)
SELECT DISTINCT
  TRIM(vp_id_raw) AS vp_id,
  NULLIF(TRIM(vp_name_raw), '') AS vp_name
FROM staging_workforce
WHERE NULLIF(TRIM(vp_id_raw), '') IS NOT NULL
  AND TRIM(vp_id_raw) NOT REGEXP '^0+$'
ON DUPLICATE KEY UPDATE
  vp_name = VALUES(vp_name);

-- 3f) svp
INSERT INTO svp (svp_id, svp_name)
SELECT DISTINCT
  TRIM(svp_id_raw) AS svp_id,
  NULLIF(TRIM(svp_name_raw), '') AS svp_name
FROM staging_workforce
WHERE NULLIF(TRIM(svp_id_raw), '') IS NOT NULL
  AND TRIM(svp_id_raw) NOT REGEXP '^0+$'
ON DUPLICATE KEY UPDATE
  svp_name = VALUES(svp_name);

-- ============================================================
-- 4) Upsert workforce (clean + convert)
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
  NULLIF(TRIM(first_name_raw), '')  AS first_name,
  NULLIF(TRIM(last_name_raw), '')   AS last_name,

  NULLIF(TRIM(job_code_raw), '')    AS job_code,
  NULLIF(TRIM(pay_band_raw), '')    AS pay_band,

  CASE
    WHEN NULLIF(TRIM(tenure_raw), '') IS NULL THEN NULL
    WHEN TRIM(tenure_raw) REGEXP '^[0-9]+$' THEN CAST(TRIM(tenure_raw) AS UNSIGNED)
    ELSE NULL
  END AS tenure,

  -- Anniversary date parsing
  CASE
    WHEN NULLIF(TRIM(anniversary_raw), '') IS NULL THEN NULL
    WHEN TRIM(anniversary_raw) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(anniversary_raw), '%Y-%m-%d')
    WHEN TRIM(anniversary_raw) REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(anniversary_raw), '%m/%d/%Y')
    WHEN TRIM(anniversary_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(anniversary_raw), '%e-%b-%Y')
    WHEN TRIM(anniversary_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(anniversary_raw), '%e-%b-%y')
    ELSE NULL
  END AS anniversary,

  -- Birthday date parsing
  CASE
    WHEN NULLIF(TRIM(birthday_raw), '') IS NULL THEN NULL
    WHEN TRIM(birthday_raw) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(birthday_raw), '%Y-%m-%d')
    WHEN TRIM(birthday_raw) REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(birthday_raw), '%m/%d/%Y')
    WHEN TRIM(birthday_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(birthday_raw), '%e-%b-%Y')
    WHEN TRIM(birthday_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(birthday_raw), '%e-%b-%y')
    ELSE NULL
  END AS birthday,

  NULLIF(TRIM(work_city_raw), '')   AS work_city,
  NULLIF(TRIM(state_raw), '')       AS state,
  NULLIF(TRIM(work_postal_raw), '') AS work_postal,

  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20) AS organization_id,

  -- hierarchy ids: empty OR all-zero => NULL
  CASE
    WHEN NULLIF(TRIM(manager_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(manager_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(manager_id_raw)
  END AS manager_id,

  CASE
    WHEN NULLIF(TRIM(director_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(director_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(director_id_raw)
  END AS director_id,

  CASE
    WHEN NULLIF(TRIM(vp_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(vp_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(vp_id_raw)
  END AS vp_id,

  CASE
    WHEN NULLIF(TRIM(svp_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(svp_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(svp_id_raw)
  END AS svp_id

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
-- 5) Diagnostics (the “what is blank?” answers)
-- ============================================================

-- A) Totally empty CSV rows (blank lines)
SELECT COUNT(*) AS staging_fully_empty_rows
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NULL
  AND NULLIF(TRIM(first_name_raw), '') IS NULL
  AND NULLIF(TRIM(last_name_raw), '') IS NULL
  AND NULLIF(TRIM(job_code_raw), '') IS NULL
  AND NULLIF(TRIM(organization_name_raw), '') IS NULL
  AND NULLIF(TRIM(manager_id_raw), '') IS NULL
  AND NULLIF(TRIM(director_id_raw), '') IS NULL
  AND NULLIF(TRIM(vp_id_raw), '') IS NULL
  AND NULLIF(TRIM(svp_id_raw), '') IS NULL;

-- B) Duplicate employee IDs in staging
SELECT TRIM(employee_id_raw) AS employee_id, COUNT(*) AS cnt
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
GROUP BY TRIM(employee_id_raw)
HAVING COUNT(*) > 1
ORDER BY cnt DESC, employee_id;

-- C) Rows where hierarchy ids are "0 / 00 / 0000" etc (your director issue)
SELECT employee_id_raw, manager_id_raw, director_id_raw, vp_id_raw, svp_id_raw
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
  AND (
    TRIM(manager_id_raw)  REGEXP '^0+$'
    OR TRIM(director_id_raw) REGEXP '^0+$'
    OR TRIM(vp_id_raw)       REGEXP '^0+$'
    OR TRIM(svp_id_raw)      REGEXP '^0+$'
  )
LIMIT 200;

-- D) Workforce rows with NULL hierarchy pointers (top-of-chain)
SELECT employee_id, first_name, last_name, manager_id, director_id, vp_id, svp_id
FROM workforce
WHERE manager_id IS NULL OR director_id IS NULL OR vp_id IS NULL OR svp_id IS NULL
LIMIT 200;
