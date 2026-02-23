-- ============================================================
-- seed.sql — Workforce Dashboard
-- Pipeline: CSV → staging_workforce → job / organization /
--           location → workforce → login
-- ============================================================
USE dashboard_prod;

-- ============================================================
-- 0) Staging table (raw CSV strings, no constraints)
-- ============================================================
CREATE TABLE IF NOT EXISTS staging_workforce (
  employee_id_raw       VARCHAR(50),
  first_name_raw        VARCHAR(255),
  last_name_raw         VARCHAR(255),
  job_code_raw          VARCHAR(50),
  title_raw             VARCHAR(255),
  job_type_raw          VARCHAR(255),
  role_raw              VARCHAR(50),
  pay_band_raw          VARCHAR(255),
  tenure_raw            VARCHAR(50),
  anniversary_raw       VARCHAR(50),
  birthday_raw          VARCHAR(50),
  organization_name_raw VARCHAR(255),
  work_city_raw         VARCHAR(255),
  work_postal_raw       VARCHAR(50),
  state_raw             VARCHAR(10),
  manager_name_raw      VARCHAR(255),
  manager_id_raw        VARCHAR(50),
  director_name_raw     VARCHAR(255),
  director_id_raw       VARCHAR(50),
  vp_name_raw           VARCHAR(255),
  vp_id_raw             VARCHAR(50),
  svp_name_raw          VARCHAR(255),
  svp_id_raw            VARCHAR(50)
) ENGINE=InnoDB;

-- ============================================================
-- 1) Load CSV into staging
--    (LOAD DATA path is already configured — do not change)
-- ============================================================
TRUNCATE TABLE staging_workforce;

LOAD DATA LOCAL INFILE '/var/www/dashboard/sql/workforce_clean.csv'
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

-- ============================================================
-- 2) Populate lookup tables (must come before workforce
--    so FK references are valid)
-- ============================================================

-- 2a) ORGANIZATION
--     AUTO_INCREMENT INT pk — just insert the name, MySQL
--     generates the org_id. UNIQUE on organization_name
--     prevents duplicates on re-run.
INSERT INTO organization (organization_name)
SELECT DISTINCT
  NULLIF(TRIM(organization_name_raw), '') AS organization_name
FROM staging_workforce
WHERE NULLIF(TRIM(organization_name_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  organization_name = VALUES(organization_name);  -- no-op, just avoids error on re-run

-- 2b) LOCATION
--     Unique constraint on (work_city, state, work_postal)
--     prevents duplicates. AUTO_INCREMENT generates location_id.
INSERT INTO location (work_city, state, work_postal)
SELECT DISTINCT
  NULLIF(TRIM(work_city_raw),   '') AS work_city,
  NULLIF(TRIM(state_raw),       '') AS state,
  NULLIF(TRIM(work_postal_raw), '') AS work_postal
FROM staging_workforce
WHERE NULLIF(TRIM(work_city_raw),   '') IS NOT NULL
  AND NULLIF(TRIM(state_raw),       '') IS NOT NULL
  AND NULLIF(TRIM(work_postal_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  work_city = VALUES(work_city);  -- no-op on re-run

-- 2c) JOB
--     title and pay_band live HERE, not on workforce.
--     One job_code can have exactly one title / job_type / pay_band.
--     If the CSV has conflicting values for the same job_code
--     (shouldn't happen, but just in case), last write wins.
INSERT INTO job (job_code, title, job_type, pay_band)
SELECT DISTINCT
  NULLIF(TRIM(job_code_raw),  '') AS job_code,
  NULLIF(TRIM(title_raw),     '') AS title,
  NULLIF(TRIM(job_type_raw),  '') AS job_type,
  NULLIF(TRIM(pay_band_raw),  '') AS pay_band
FROM staging_workforce
WHERE NULLIF(TRIM(job_code_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  title    = VALUES(title),
  job_type = VALUES(job_type),
  pay_band = VALUES(pay_band);

-- ============================================================
-- 3) Populate WORKFORCE
--
--  Key decisions made here:
--
--  a) FK_CHECKS OFF during insert.
--     The manager_id / director_id / vp_id / svp_id columns
--     all reference OTHER rows in this same table. If we try
--     to insert employee A with manager_id = B before B is
--     inserted, MySQL throws an FK violation. Turning checks
--     off lets the whole table load first; the FK integrity
--     check in section 4 then confirms everything resolved.
--
--  b) Hierarchy ID cleanup:
--     - Empty string → NULL
--     - All-zero strings ("0", "000000") → NULL
--       (directors/VPs who ARE the top of their chain have
--        their own ID listed as 0 in the source CSV)
--     - Whitespace-only → NULL
--
--  c) org_id and location_id are resolved via subquery joins
--     back to the lookup tables we just populated.
--
--  d) Dates support four formats found in the CSV:
--       YYYY-MM-DD | MM/DD/YYYY | D-Mon-YYYY | D-Mon-YY
--     Plus bare D-Mon (no year) → assumes current year.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO workforce (
  employee_id,
  first_name,
  last_name,
  tenure,
  anniversary,
  birthday,
  role,
  job_code,
  org_id,
  location_id,
  manager_id,
  director_id,
  vp_id,
  svp_id
)
SELECT

  NULLIF(TRIM(s.employee_id_raw), '') AS employee_id,
  NULLIF(TRIM(s.first_name_raw),  '') AS first_name,
  NULLIF(TRIM(s.last_name_raw),   '') AS last_name,

  -- tenure: only accept clean integers
  CASE
    WHEN NULLIF(TRIM(s.tenure_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.tenure_raw) REGEXP '^[0-9]+$' THEN CAST(TRIM(s.tenure_raw) AS UNSIGNED)
    ELSE NULL
  END AS tenure,

  -- anniversary
  CASE
    WHEN NULLIF(TRIM(s.anniversary_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.anniversary_raw) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(s.anniversary_raw), '%Y-%m-%d')
    WHEN TRIM(s.anniversary_raw) REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(s.anniversary_raw), '%m/%d/%Y')
    WHEN TRIM(s.anniversary_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(s.anniversary_raw), '%e-%b-%Y')
    WHEN TRIM(s.anniversary_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(s.anniversary_raw), '%e-%b-%y')
    WHEN TRIM(s.anniversary_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}$'
      THEN STR_TO_DATE(CONCAT(TRIM(s.anniversary_raw), '-', YEAR(CURDATE())), '%e-%b-%Y')
    ELSE NULL
  END AS anniversary,

  -- birthday
  CASE
    WHEN NULLIF(TRIM(s.birthday_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.birthday_raw) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(s.birthday_raw), '%Y-%m-%d')
    WHEN TRIM(s.birthday_raw) REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(s.birthday_raw), '%m/%d/%Y')
    WHEN TRIM(s.birthday_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{4}$'
      THEN STR_TO_DATE(TRIM(s.birthday_raw), '%e-%b-%Y')
    WHEN TRIM(s.birthday_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}-[0-9]{2}$'
      THEN STR_TO_DATE(TRIM(s.birthday_raw), '%e-%b-%y')
    WHEN TRIM(s.birthday_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}$'
      THEN STR_TO_DATE(CONCAT(TRIM(s.birthday_raw), '-', YEAR(CURDATE())), '%e-%b-%Y')
    ELSE NULL
  END AS birthday,

  NULLIF(TRIM(s.role_raw), '') AS role,

  NULLIF(TRIM(s.job_code_raw), '') AS job_code,

  -- org_id: look up the auto-generated INT from the name
  (SELECT o.org_id
   FROM organization o
   WHERE o.organization_name = NULLIF(TRIM(s.organization_name_raw), '')
   LIMIT 1) AS org_id,

  -- location_id: look up by the three location fields combined
  (SELECT l.location_id
   FROM location l
   WHERE l.work_city   = NULLIF(TRIM(s.work_city_raw),   '')
     AND l.state       = NULLIF(TRIM(s.state_raw),       '')
     AND l.work_postal = NULLIF(TRIM(s.work_postal_raw), '')
   LIMIT 1) AS location_id,

  -- hierarchy IDs — empty, whitespace-only, or all-zero → NULL
  -- This handles directors/VPs/SVPs who are at the top of their
  -- chain and have their own ID listed as 0 in the source data.
  CASE
    WHEN NULLIF(TRIM(s.manager_id_raw),  '') IS NULL THEN NULL
    WHEN TRIM(s.manager_id_raw)  REGEXP '^0+$'       THEN NULL
    ELSE TRIM(s.manager_id_raw)
  END AS manager_id,

  CASE
    WHEN NULLIF(TRIM(s.director_id_raw), '') IS NULL THEN NULL
    WHEN TRIM(s.director_id_raw) REGEXP '^0+$'       THEN NULL
    ELSE TRIM(s.director_id_raw)
  END AS director_id,

  CASE
    WHEN NULLIF(TRIM(s.vp_id_raw),       '') IS NULL THEN NULL
    WHEN TRIM(s.vp_id_raw)       REGEXP '^0+$'       THEN NULL
    ELSE TRIM(s.vp_id_raw)
  END AS vp_id,

  CASE
    WHEN NULLIF(TRIM(s.svp_id_raw),      '') IS NULL THEN NULL
    WHEN TRIM(s.svp_id_raw)      REGEXP '^0+$'       THEN NULL
    ELSE TRIM(s.svp_id_raw)
  END AS svp_id

FROM staging_workforce s
WHERE NULLIF(TRIM(s.employee_id_raw), '') IS NOT NULL

ON DUPLICATE KEY UPDATE
  first_name  = VALUES(first_name),
  last_name   = VALUES(last_name),
  tenure      = VALUES(tenure),
  anniversary = VALUES(anniversary),
  birthday    = VALUES(birthday),
  role        = VALUES(role),
  job_code    = VALUES(job_code),
  org_id      = VALUES(org_id),
  location_id = VALUES(location_id),
  manager_id  = VALUES(manager_id),
  director_id = VALUES(director_id),
  vp_id       = VALUES(vp_id),
  svp_id      = VALUES(svp_id);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 4) Populate LOGIN
--    Username  = employee_id (as agreed)
--    Password  = SHA2 hash of employee_id as a placeholder.
--                In production your PHP registration flow
--                should replace this with a proper bcrypt hash
--                via password_hash().
-- ============================================================
INSERT INTO login (username, password, employee_id)
SELECT
  employee_id,
  SHA2(employee_id, 256) AS password,   -- placeholder — replace with bcrypt in PHP
  employee_id
FROM workforce
ON DUPLICATE KEY UPDATE
  username    = VALUES(username),
  employee_id = VALUES(employee_id);
  -- intentionally NOT updating password so existing passwords survive re-seeding

-- ============================================================
-- 5) Diagnostics
-- ============================================================

-- 5a) Row counts across all tables
SELECT
  (SELECT COUNT(*) FROM staging_workforce) AS staging_rows,
  (SELECT COUNT(*) FROM organization)      AS org_rows,
  (SELECT COUNT(*) FROM location)          AS location_rows,
  (SELECT COUNT(*) FROM job)               AS job_rows,
  (SELECT COUNT(*) FROM workforce)         AS workforce_rows,
  (SELECT COUNT(*) FROM login)             AS login_rows;

-- 5b) Any employees whose org or location didn't resolve
--     (these will show NULL org_id or location_id in workforce)
SELECT COUNT(*) AS missing_org_id
FROM workforce
WHERE org_id IS NULL;

SELECT COUNT(*) AS missing_location_id
FROM workforce
WHERE location_id IS NULL;

-- 5c) Unresolved hierarchy pointers (ideally all zero after FK checks back on)
SELECT COUNT(*) AS unresolved_hierarchy_refs
FROM workforce w
LEFT JOIN workforce mgr ON mgr.employee_id = w.manager_id
LEFT JOIN workforce dir ON dir.employee_id = w.director_id
LEFT JOIN workforce vp  ON vp.employee_id  = w.vp_id
LEFT JOIN workforce svp ON svp.employee_id = w.svp_id
WHERE (w.manager_id  IS NOT NULL AND mgr.employee_id IS NULL)
   OR (w.director_id IS NOT NULL AND dir.employee_id IS NULL)
   OR (w.vp_id       IS NOT NULL AND vp.employee_id  IS NULL)
   OR (w.svp_id      IS NOT NULL AND svp.employee_id IS NULL);

-- 5d) Date parse failures (staging had a value but workforce got NULL)
SELECT
  s.employee_id_raw,
  s.anniversary_raw, w.anniversary,
  s.birthday_raw,    w.birthday
FROM staging_workforce s
JOIN workforce w ON w.employee_id = TRIM(s.employee_id_raw)
WHERE (NULLIF(TRIM(s.anniversary_raw), '') IS NOT NULL AND w.anniversary IS NULL)
   OR (NULLIF(TRIM(s.birthday_raw),    '') IS NOT NULL AND w.birthday    IS NULL)
LIMIT 50;

-- 5e) Duplicate employee IDs in staging (merged by ON DUPLICATE KEY UPDATE)
SELECT TRIM(employee_id_raw) AS employee_id, COUNT(*) AS cnt
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
GROUP BY TRIM(employee_id_raw)
HAVING COUNT(*) > 1
ORDER BY cnt DESC
LIMIT 20;
