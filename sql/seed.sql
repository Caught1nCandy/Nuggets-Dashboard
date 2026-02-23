USE dashboard_prod;

-- ============================================================
-- 0) Ensure staging table exists (raw CSV strings)
-- ============================================================
CREATE TABLE IF NOT EXISTS staging_workforce (
  employee_id_raw           VARCHAR(50),
  first_name_raw            VARCHAR(255),
  last_name_raw             VARCHAR(255),
  job_code_raw              VARCHAR(50),
  title_raw                 VARCHAR(255),
  job_type_raw              VARCHAR(255),
  role_raw                  VARCHAR(50),
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
-- 1) Clear staging and load the cleaned CSV
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
-- 2) Load lookup tables first (FK-safe)
-- ============================================================

-- 2a) organization: stable generated ID from name
INSERT INTO organization (organization_id, organization_name)
SELECT DISTINCT
  SUBSTRING(MD5(NULLIF(TRIM(organization_name_raw), '')), 1, 20) AS organization_id,
  NULLIF(TRIM(organization_name_raw), '') AS organization_name
FROM staging_workforce
WHERE NULLIF(TRIM(organization_name_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  organization_name = VALUES(organization_name);

-- 2b) job
INSERT INTO job (job_code, job_type)
SELECT DISTINCT
  NULLIF(TRIM(job_code_raw), '') AS job_code,
  NULLIF(TRIM(job_type_raw), '') AS job_type
FROM staging_workforce
WHERE NULLIF(TRIM(job_code_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  job_type = VALUES(job_type);

-- ============================================================
-- 3) Upsert into WORKFORCE (clean + normalize)
-- ============================================================

/*
  Helper logic used below:
  - "blank" ID = NULL if empty OR all zeros (0, 00, 000000)
  - Dates:
    Supports:
      YYYY-MM-DD
      MM/DD/YYYY
      D-Mon-YYYY
      D-Mon-YY
      D-Mon        (assumes current year)
*/

INSERT INTO workforce (
  employee_id,
  first_name,
  last_name,
  job_code,
  title,
  job_type,
  role,
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
  NULLIF(TRIM(title_raw), '')       AS title,
  NULLIF(TRIM(job_type_raw), '')    AS job_type,
  NULLIF(TRIM(role_raw), '')        AS role,

  NULLIF(TRIM(pay_band_raw), '')    AS pay_band,

  CASE
    WHEN NULLIF(TRIM(tenure_raw), '') IS NULL THEN NULL
    WHEN TRIM(tenure_raw) REGEXP '^[0-9]+$' THEN CAST(TRIM(tenure_raw) AS UNSIGNED)
    ELSE NULL
  END AS tenure,

  -- anniversary
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

    -- like 4-Jun (no year): assume current year
    WHEN TRIM(anniversary_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}$'
      THEN STR_TO_DATE(CONCAT(TRIM(anniversary_raw), '-', YEAR(CURDATE())), '%e-%b-%Y')

    ELSE NULL
  END AS anniversary,

  -- birthday
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

    -- like 5-Oct (no year): assume current year
    WHEN TRIM(birthday_raw) REGEXP '^[0-9]{1,2}-[A-Za-z]{3}$'
      THEN STR_TO_DATE(CONCAT(TRIM(birthday_raw), '-', YEAR(CURDATE())), '%e-%b-%Y')

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
  title           = VALUES(title),
  job_type        = VALUES(job_type),
  role            = VALUES(role),
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
-- 4) Diagnostics (quick proof everything is sane)
-- ============================================================

-- 4a) counts
SELECT
  (SELECT COUNT(*) FROM staging_workforce) AS staging_rows,
  (SELECT COUNT(*) FROM workforce) AS workforce_rows;

-- 4b) staging duplicate employee IDs (these merge in workforce)
SELECT TRIM(employee_id_raw) AS employee_id, COUNT(*) AS cnt
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
GROUP BY TRIM(employee_id_raw)
HAVING COUNT(*) > 1
ORDER BY cnt DESC, employee_id;

-- 4c) rows where staging had "0" hierarchy values
SELECT COUNT(*) AS staging_zeroish_hierarchy_rows
FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
  AND (
    TRIM(manager_id_raw)  REGEXP '^0+$'
    OR TRIM(director_id_raw) REGEXP '^0+$'
    OR TRIM(vp_id_raw)       REGEXP '^0+$'
    OR TRIM(svp_id_raw)      REGEXP '^0+$'
  );

-- 4d) unresolved hierarchy pointers (should be 0 if everyone exists in workforce)
SELECT COUNT(*) AS unresolved_hierarchy_refs
FROM workforce w
LEFT JOIN workforce m  ON m.employee_id  = w.manager_id
LEFT JOIN workforce d  ON d.employee_id  = w.director_id
LEFT JOIN workforce v  ON v.employee_id  = w.vp_id
LEFT JOIN workforce sv ON sv.employee_id = w.svp_id
WHERE (w.manager_id  IS NOT NULL AND m.employee_id  IS NULL)
   OR (w.director_id IS NOT NULL AND d.employee_id  IS NULL)
   OR (w.vp_id       IS NOT NULL AND v.employee_id  IS NULL)
   OR (w.svp_id      IS NOT NULL AND sv.employee_id IS NULL);

-- 4e) date parse failures where staging had a value but workforce became NULL
SELECT s.employee_id_raw, s.anniversary_raw, w.anniversary, s.birthday_raw, w.birthday
FROM staging_workforce s
JOIN workforce w ON w.employee_id = TRIM(s.employee_id_raw)
WHERE (NULLIF(TRIM(s.anniversary_raw), '') IS NOT NULL AND w.anniversary IS NULL)
   OR (NULLIF(TRIM(s.birthday_raw), '')    IS NOT NULL AND w.birthday    IS NULL)
LIMIT 50;
