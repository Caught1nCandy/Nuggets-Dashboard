-- ============================================================
-- seed.sql — STAGING -> LOOKUPS -> WORKFORCE (2-pass for self-FKs)
-- For loopback-x4 schema, CSV includes Role column
-- Designed for "Apply DB button" execution (server-side)
-- ============================================================

USE dashboard_prod;

-- 0) staging (raw)
CREATE TABLE IF NOT EXISTS staging_workforce (
  employee_id_raw           VARCHAR(50),
  first_name_raw            VARCHAR(255),
  last_name_raw             VARCHAR(255),
  job_code_raw              VARCHAR(50),
  title_raw                 VARCHAR(255),
  job_type_raw              VARCHAR(255),
  role_raw                  VARCHAR(255),
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

TRUNCATE TABLE staging_workforce;

-- 1) load CSV (SERVER-SIDE; file must be readable by MariaDB server)
-- Put the file in secure_file_priv dir (commonly /var/lib/mysql-files/)
LOAD DATA INFILE '/var/lib/mysql-files/workforce.csv'
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
  role_raw,
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

-- 2) lookups
INSERT INTO organization (organization_id, organization_name)
SELECT DISTINCT
  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20),
  NULLIF(TRIM(organization_name_raw), '')
FROM staging_workforce
WHERE NULLIF(TRIM(organization_name_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE organization_name = VALUES(organization_name);

INSERT INTO job (job_code, job_type)
SELECT DISTINCT
  NULLIF(TRIM(job_code_raw), ''),
  NULLIF(TRIM(job_type_raw), '')
FROM staging_workforce
WHERE NULLIF(TRIM(job_code_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE job_type = VALUES(job_type);

-- 3) workforce PASS 1 (insert everyone with NULL hierarchy pointers)
-- This avoids self-FK failures.
INSERT INTO workforce (
  employee_id, first_name, last_name,
  job_code, pay_band, tenure,
  anniversary, birthday,
  work_city, state, work_postal,
  organization_id, role,
  manager_id, director_id, vp_id, svp_id
)
SELECT
  NULLIF(TRIM(employee_id_raw), '') AS employee_id,
  COALESCE(NULLIF(TRIM(first_name_raw), ''), 'UNKNOWN') AS first_name,
  COALESCE(NULLIF(TRIM(last_name_raw), ''),  'UNKNOWN') AS last_name,

  NULLIF(TRIM(job_code_raw), '') AS job_code,
  COALESCE(NULLIF(TRIM(pay_band_raw), ''), 'UNKNOWN') AS pay_band,

  COALESCE(
    CASE
      WHEN NULLIF(TRIM(tenure_raw), '') IS NULL THEN NULL
      WHEN TRIM(tenure_raw) REGEXP '^[0-9]+$' THEN CAST(TRIM(tenure_raw) AS UNSIGNED)
      ELSE NULL
    END,
    0
  ) AS tenure,

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

  COALESCE(NULLIF(TRIM(work_city_raw), ''), 'UNKNOWN') AS work_city,
  COALESCE(NULLIF(TRIM(state_raw), ''), 'NA') AS state,
  COALESCE(NULLIF(TRIM(work_postal_raw), ''), 'UNKNOWN') AS work_postal,

  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20) AS organization_id,

  COALESCE(NULLIF(TRIM(role_raw), ''), 'Employee') AS role,

  NULL, NULL, NULL, NULL
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  first_name = VALUES(first_name),
  last_name  = VALUES(last_name),
  job_code   = VALUES(job_code),
  pay_band   = VALUES(pay_band),
  tenure     = VALUES(tenure),
  anniversary = VALUES(anniversary),
  birthday    = VALUES(birthday),
  work_city   = VALUES(work_city),
  state       = VALUES(state),
  work_postal = VALUES(work_postal),
  organization_id = VALUES(organization_id),
  role = VALUES(role);

-- 4) workforce PASS 2 (apply hierarchy ids after all employees exist)
UPDATE workforce w
JOIN staging_workforce s
  ON w.employee_id = NULLIF(TRIM(s.employee_id_raw), '')
SET
  w.manager_id = CASE
    WHEN NULLIF(TRIM(s.manager_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.manager_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(s.manager_id_raw)
  END,
  w.director_id = CASE
    WHEN NULLIF(TRIM(s.director_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.director_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(s.director_id_raw)
  END,
  w.vp_id = CASE
    WHEN NULLIF(TRIM(s.vp_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.vp_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(s.vp_id_raw)
  END,
  w.svp_id = CASE
    WHEN NULLIF(TRIM(s.svp_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.svp_id_raw) REGEXP '^0+$' THEN NULL
    ELSE TRIM(s.svp_id_raw)
  END;

-- 5) diagnostics
SELECT COUNT(*) AS staging_blank_employee_id
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NULL;

SELECT TRIM(employee_id_raw) AS employee_id, COUNT(*) AS cnt
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
GROUP BY TRIM(employee_id_raw)
HAVING COUNT(*) > 1
ORDER BY cnt DESC, employee_id;

SELECT
  (SELECT COUNT(*) FROM staging_workforce) AS staging_rows,
  (SELECT COUNT(*) FROM workforce) AS workforce_rows;

-- Find any hierarchy pointers that still don't resolve (should be 0)
SELECT w.employee_id, w.manager_id, w.director_id, w.vp_id, w.svp_id
FROM workforce w
LEFT JOIN workforce m ON m.employee_id = w.manager_id
LEFT JOIN workforce d ON d.employee_id = w.director_id
LEFT JOIN workforce v ON v.employee_id = w.vp_id
LEFT JOIN workforce sv ON sv.employee_id = w.svp_id
WHERE (w.manager_id IS NOT NULL AND m.employee_id IS NULL)
   OR (w.director_id IS NOT NULL AND d.employee_id IS NULL)
   OR (w.vp_id IS NOT NULL AND v.employee_id IS NULL)
   OR (w.svp_id IS NOT NULL AND sv.employee_id IS NULL)
LIMIT 200;
