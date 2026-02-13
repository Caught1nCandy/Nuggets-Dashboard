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

-- ===============================
-- 3) Upsert into final table
-- ===============================
INSERT INTO workforce (
  employee_id,
  first_name, last_name,
  job_code, title, job_type, pay_band,
  tenure,
  anniversary, birthday,
  organization_name,
  work_city, work_postal, state,
  manager_name, manager_id,
  director_name, director_id,
  vp_name, vp_id,
  svp_name, svp_id
)
SELECT
  CAST(NULLIF(TRIM(employee_id_raw), '') AS UNSIGNED) AS employee_id,

  NULLIF(TRIM(first_name_raw), '') AS first_name,
  NULLIF(TRIM(last_name_raw), '')  AS last_name,

  NULLIF(TRIM(job_code_raw), '')   AS job_code,
  NULLIF(TRIM(title_raw), '')      AS title,
  NULLIF(TRIM(job_type_raw), '')   AS job_type,
  NULLIF(TRIM(pay_band_raw), '')   AS pay_band,

  CAST(NULLIF(TRIM(tenure_raw), '') AS DECIMAL(10,2)) AS tenure,

  NULLIF(TRIM(anniversary_raw), '') AS anniversary,
  NULLIF(TRIM(birthday_raw), '')    AS birthday,

  NULLIF(TRIM(organization_name_raw), '') AS organization_name,

  NULLIF(TRIM(work_city_raw), '')   AS work_city,
  NULLIF(TRIM(work_postal_raw), '') AS work_postal,
  NULLIF(TRIM(state_raw), '')       AS state,

  NULLIF(TRIM(manager_name_raw), '') AS manager_name,
  CAST(NULLIF(TRIM(manager_id_raw), '') AS UNSIGNED) AS manager_id,

  NULLIF(TRIM(director_name_raw), '') AS director_name,
  CAST(NULLIF(TRIM(director_id_raw), '') AS UNSIGNED) AS director_id,

  NULLIF(TRIM(vp_name_raw), '') AS vp_name,
  CAST(NULLIF(TRIM(vp_id_raw), '') AS UNSIGNED) AS vp_id,

  NULLIF(TRIM(svp_name_raw), '') AS svp_name,
  CAST(NULLIF(TRIM(svp_id_raw), '') AS UNSIGNED) AS svp_id

FROM staging_workforce
WHERE NULLIF(TRIM(employee_id_raw), '') IS NOT NULL
ON DUPLICATE KEY UPDATE
  first_name = VALUES(first_name),
  last_name  = VALUES(last_name),
  job_code   = VALUES(job_code),
  title      = VALUES(title),
  job_type   = VALUES(job_type),
  pay_band   = VALUES(pay_band),
  tenure     = VALUES(tenure),
  anniversary = VALUES(anniversary),
  birthday    = VALUES(birthday),
  organization_name = VALUES(organization_name),
  work_city   = VALUES(work_city),
  work_postal = VALUES(work_postal),
  state       = VALUES(state),
  manager_name = VALUES(manager_name),
  manager_id   = VALUES(manager_id),
  director_name = VALUES(director_name),
  director_id   = VALUES(director_id),
  vp_name = VALUES(vp_name),
  vp_id   = VALUES(vp_id),
  svp_name = VALUES(svp_name),
  svp_id   = VALUES(svp_id);
