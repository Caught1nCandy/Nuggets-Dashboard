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

-- ============================================================
-- Login passwords — appended to seed.sql
-- Safe to run multiple times (ON DUPLICATE KEY UPDATE)
-- Passwords truncated to 10 characters
-- ============================================================

INSERT INTO login (username, password, employee_id)
VALUES
  ('815205', '04fafe43560d63e20f73640ca5b00a871854d5392a9a132c0d203c4e391b7c5b', '815205'),
  ('3228892', '5f8d5d161e5cf821310f9b2418f9d3642dd52225b09260da007b7dba636e066a', '3228892'),
  ('641042', '4eae40faafff33e4a2209c8bb89e2ade530529bf88949e8637bed2b14492c138', '641042'),
  ('641227', 'dd2a179d34e9663fb6df98946a4bc13388ec82c74241cea9934407b8e38e6276', '641227'),
  ('641330', 'ed8fce216a542623213ee7cf510b284f7dae883dd1cbf503bb1ec4461376703c', '641330'),
  ('641607', '11e940afd26dde2e553c9f736e5375e691c025e88fb6b4a979427583a96e7f8d', '641607'),
  ('641752', '9f231739fef13a06b363bad7737fe36c2e2517ca2db93bb7aac7dc3834ca0856', '641752'),
  ('641222', '416b7c3aa8bc1826cc4ca5a6b401ebea3ca344117e162c3f50775da5dcb3aa9a', '641222'),
  ('641923', '917dc7005be6bb11d9cd7af421b99a1a0e5be0923ac65d709e7843ad991d4d2d', '641923'),
  ('641172', '1dc5cfca0d6a2fc851804c2f11b10bfb9216c2f316239465dae3dde2c4a6a203', '641172'),
  ('641185', '206d5d88fb75e45173b8fcd98ea08b52650dffd88854dc1583e7e93725a8c54b', '641185'),
  ('707373', 'b913a2cbb89c43b2338828bf83749e4b613c53de71bf532d8346f5aa51150c5a', '707373'),
  ('641817', '734e44900f4efea7d16075586466c4ed1ff4a39bd51d8a55288842cdf67691c2', '641817'),
  ('641906', 'acfb21419700934391d553f405f616222908480ecce9c2ccf15e6b88d261d636', '641906'),
  ('641283', 'bd3d2725ed7d9925eb21d9a78687a2749c2fd40b5978233b8b799bef4f47f8fa', '641283'),
  ('641768', 'd9d79390d6f2b14ae036010f4609899d6700109c3eda8fed72305c1a0533df3a', '641768'),
  ('641200', 'dcc7d26a01d5415122aac9a1a1e60083c7f189a1a285ac30beb0c99aa3194f71', '641200'),
  ('642433', 'f71b7a554dae7e9eb4c64ab97eda9704f30beb50801100428c440b11407b8e4f', '642433'),
  ('181521', '31c6d914121f141804fba268866655c58e19bea296433d9f3bbe26e0ac2a72f2', '181521'),
  ('248871', '8c5d78928b1328369aa68fd5673405285227a4349188f83eb4c1084ded59a3d2', '248871'),
  ('298971', 'bca85ff98fb9257e1581a8f412250b2844c0a15024fd0a80b5bb92d5931f0d83', '298971'),
  ('641199', '9ab7afd0de3b7f63cf1c59bb4353d4ff27bc460797802cd6cd80c239a80ae563', '641199'),
  ('641530', 'e4671f96cf343fc8bd98b0b8aa593a22ecd83f7af1ce45433a3c16b7f2f7fb35', '641530'),
  ('641187', '7b0d4f566e2fe5d6911e80650e71682969902f173430a247b84e52643a0c288e', '641187'),
  ('641136', '2917a77e6f7a79f0f257c265889e56968e0dbb883d1131425f5a30fae4bc0a50', '641136'),
  ('641235', '519680bbe00a1c3aa5096c02bfef67138d50d099beb6c66a7e655b0a7c1060b2', '641235'),
  ('641270', 'c68b9bd9207c91093d6a0911ed83abde23ddf444863b321b644b2d8310f2eb94', '641270'),
  ('641314', '8d308a981b585eda8729275e65f99eb536e10476d852ad6700f4533b78affda5', '641314'),
  ('641315', '4ccdcecc897c5091c055423bec3d962b158e9e14e864ed51494f1ba78181cdff', '641315'),
  ('641666', '4ab7f8cb8f2c56a1bade8df39838b32d80125d0e6ef34bf35055b001e7914b8e', '641666'),
  ('641677', '26345c2baf8857d604c2a8ced55384f601ccb96e1fca7b013f2aa90aca78c711', '641677'),
  ('641695', '48838fb159d71ba18f7d4b4b26120f775563a920dad54261b81063547d849fc1', '641695'),
  ('641754', '76300541223e4902d914f8ca98fcee56d887f99712f4f165c172515015aa171e', '641754'),
  ('641778', 'd0ed5e6e323ac9eb6539a6667bec4283de9e61b0bad917a9128c0ae378d7ecd5', '641778'),
  ('641790', '7df77f35b22210106c4a0c339ea107fa8847e6e079c381a343cd67cf9c52bb30', '641790'),
  ('641826', '5ff0fc66169b16db32cb3b5ad845129153cfc84defa533994a584189b10cfacb', '641826'),
  ('641832', '94744b2c6f235a93c7c11d048443ba6524bf6a869e9c7b4ec28a50a72b95088d', '641832'),
  ('656748', '2ad84889597fd5bbdec3f2bf2966ec035e105e330955ca4211c5dd1dd235bf30', '656748'),
  ('691740', '17ca117fcaa5eed994badddc6526aee1b78ac5a5f3ab0a9fd59ca9a4af07473c', '691740'),
  ('694852', '7539ee3591a2aff25d0e7818c51c752bae05e1b738474a2d5bce96fddeb28a28', '694852'),
  ('704164', '5bf6141b2fc478f48a3aa71311b02568adb93c361cd06d8eb4660ae2fe0c4723', '704164'),
  ('707831', '4359b3202870ac9a9b6269fb3561dc38d1e20652b1fef0ca2d534b23cf43065a', '707831'),
  ('718846', '985f2bc028109f583f848dffd682b46ea3390c97fe2511784c84ade10933e9d0', '718846'),
  ('798683', 'bf8665ba1313e7ac695963424b6ad04d23143c4f0a8d61a2debc38ae8f90844f', '798683'),
  ('4326904', 'e7e4cdb8fe5eae2629889b9489cbe9598101f2f12d1f29cbe67c610168ca6815', '4326904'),
  ('3165867', 'a8254322b44667c66f5be14903abc150759667b8aa5fbf41aa7b36b2fb99d495', '3165867'),
  ('755246', 'f882c631a2f18a8deacbec5b4848d09b1dcbeff41e12538940f1cb54619acd20', '755246'),
  ('839430', '1d55bad3d174987c04df1fd1916f5f082f4da3f1a28cc6658a82fdf5f780624f', '839430'),
  ('3077908', '3bb94b3be214fef700c8c2c2ec14e1a4204562c3d230cc10590f33cf1cb8086d', '3077908'),
  ('3112629', 'e9449d0ba1e54455d5d11a7aa5726e97f922c10e298f28213fbb4a2314294633', '3112629'),
  ('3112633', 'dbf309e6f0d2cc1e32c47d9ea5d8f4bb04553b5a7227d264ec171fca59311cb4', '3112633'),
  ('3112634', '66b8934a9aa5dc3e623527ceec77fcb30e435879670d5a036d4c663c665e6171', '3112634'),
  ('3115055', 'cc0fbe823e03962f267af38d0734c05c64ade96d5de4c77469670285edaf0f77', '3115055'),
  ('3116156', 'fa4ad4e0649427d4707c3a18e26f754022d73c80a687580705994029d853c0c3', '3116156'),
  ('3116160', '718131029a8fa49062903046cb180f27335f6d2844d0ae446a23aa76b74627b5', '3116160'),
  ('3121293', '19e54ba4999a80dc059fab6cc994c52e1ccd92c1b375d2e0ef9a80deae490c74', '3121293'),
  ('3139103', '07fa237aa0aa15b4dbc6a6fb201552e16949dcf9fd9b179592a33c3cf33bb4e9', '3139103'),
  ('3319398', 'ef5989c7d6310d18b6954b2993dc36c03b5b36fc33352aa1e08cf7533acae60c', '3319398'),
  ('4331519', '356eb5385dc2948b8c4fb8426b94946c8c92cd561a342048d3ab64fe3898c1ac', '4331519'),
  ('4455651', 'c4e84ee6fac68ca0d3677779b9fa59621c2b76055269d4d4bb5d116eb0e777e1', '4455651'),
  ('4485604', '90b44dc794d26d8d9d5054d19a90d32a000d5c80186654f3d0e6ac57bb3cfcbc', '4485604'),
  ('4499536', '23e6e1977263accb7d8a5c0faee0e7b02bd9763b6a11de40b9cd4a341d33fda8', '4499536'),
  ('372301', '8dfeb672f42673d8c4856dcdce1fe3899f39c71ebaf82730506c5b5d38f8cec5', '372301'),
  ('641609', 'fc55347136ba63bc49eda3fce2b7fd310ff91bdc4fe6fc58feaeda3d56e253e2', '641609'),
  ('641625', 'ec57725a397dfe469636008b44423c83c139fab7eb2beec22d93be77ba0b9f32', '641625'),
  ('641638', '78d4cbfbe14c01f94fed07b4ccd070c2038a42bde693ccd1ad88c00a73a61d23', '641638'),
  ('641747', 'd6d75b33a986dcdec9cb04c8f517d9cab5ffa89339747e59ec5667c702815f22', '641747'),
  ('641772', 'f9b6efbc29aa5d1a70815f5a078e6341ee850273c88f34b805c785e1283cd508', '641772'),
  ('641773', 'fbaab7f77dccd8c13f61f23ea8a75e7e054b6a81fbc684878ef0aba80f23ee24', '641773'),
  ('641785', '55af5fe137629908116894cb54dc244b61c329f1704dbb229b9b4bb790851ebf', '641785'),
  ('641852', 'e22e1c06693aa4886b7ddcf8ebdca6ef8c416d4a2f42e890022197dbfce58e3e', '641852'),
  ('641919', '4e1ad43c9d95fde7bde17abdf93fedd28978a3b2b4500f60a8bb83c05b93dff1', '641919'),
  ('645462', '9249c4771a711c7d1aad13260116b5377e7f6288dd594f8c2a384b7227397a63', '645462'),
  ('724287', 'bd749b7f9218797fdac158d8441718a5d35a461495888e627f3443fa56275ccf', '724287'),
  ('753405', '6ac20d1b0900c24f425aba6b670baa697ad54493ae61c9d33851d1ea6cfd9eba', '753405'),
  ('754057', 'abfb519210fdc65b9c89df430deff4a1fd931490d250d5eeee1990df313fd00f', '754057'),
  ('3079108', '9c1a96ce5a07bde8a2aa29d4320ad324ad57b51fc07a511f58aba886bfbbbbd8', '3079108'),
  ('4331540', 'b54dd96685e83ea61295feea017e34ab9d5c941bb63ed2810ff9d96a55bc47b4', '4331540'),
  ('4409443', 'b9fdc67e6fc5a855aadd363168ad28893754b420ba97e7827e097b35d776246e', '4409443'),
  ('4410068', '46508b1891c5d2dcecc3c62b6c979819d64cc746e1d0ab64e06c7eb22ddb8f32', '4410068'),
  ('4485603', 'a0d496cca21e78ac792f3e28f62c4bb6c04e68320096173aabe445de84525b9e', '4485603'),
  ('100533', '0e2f09090593e96456102cdecbaf8f9e2e95408aef99c99bcb7a451f5f8cb37d', '100533'),
  ('281327', '2d03c8b9f210e9d2d40541451522df394da253a28837264755c8798e442be3e8', '281327'),
  ('298908', '7ed1fd6ec0e9f376db6ccf1f44a1d69c5d0bcce7ab2355dae16a6b2aec932758', '298908'),
  ('338590', '4e213f5817fed098aae35991076d9ff5fdfdb1ed014a17c47ff2fcc5971eb62b', '338590'),
  ('364670', '55038ccc4522412dca778bfbc759526e15e608cfe09c0881d861b505c707f0ac', '364670'),
  ('426315', 'ae38d4b7892787dabd3ccac0cde676b4dd04dacf7c8267eb6663a3167ea28474', '426315'),
  ('641135', 'a1c3d92b0e809dec3bb72f5528ad5316f08da3a840c2e01ecb0132e045350770', '641135'),
  ('641144', '05426f06526b2207a96f47275d44d46dc20363f59ac0706bc80398cb12958ce2', '641144'),
  ('641148', '40ac6f9548ec76072958be49a2f20947b9748218389feacf2f195832596e2053', '641148'),
  ('641311', 'cd5521ccf61b844ab3bbf83f3ce55e24878fc8294ff0f5d64852dd52f9e2515a', '641311'),
  ('641325', 'b430617b074567bfeb12238a6350ae610666c73a4214fa62377e1556819df78d', '641325'),
  ('641615', '6a0eff2774e33ebdea46c7bad81da82ce49077c535c5253899f4160fc5610aa7', '641615'),
  ('641623', '9c7f0a696493a523ac68759f5a6457fe3704362fa57c212f8490dd324d84b5bf', '641623'),
  ('641627', 'b3918c96fad5aaa344e4951e068dce1651a75b33b58bedfdc7c7d64f3f2ee46d', '641627'),
  ('641687', 'ccbcfb7d04d7061684d56063ea2504444c464ec809b30574fc999429b725f1d9', '641687'),
  ('641716', '643f901e0c04e266dd5d82ebeda3c515992fc9f22eb03c7e7f3deb4a8090ed21', '641716'),
  ('641723', '8a15ca86eae2d1bb5423681b7de5b1a777db0575b0dd456a3c3a54cf21ff4e90', '641723'),
  ('641729', 'ca4c9a59cb64ea131e731a9f2b4409c3504c2397541fd11dfd683cb87a96c0e8', '641729'),
  ('641767', 'ec50776fa115208c11665436ab49fcacbcaf4291f70f51fd750cf039bae797d7', '641767'),
  ('641805', 'bf6b11e76dfeb01c7ca840f144a7ba9542c401e49cbd91846b66058ea148e896', '641805'),
  ('641814', '91434a549f853303dd607bbd67b7b794c7d91a617e9b6f1fbcaed8e208d64de9', '641814'),
  ('645235', 'd4e54714cc6088a0571099ffd204e41896b522dd583c8cfd7d6e36062dd22d4d', '645235'),
  ('689123', 'd677016612861a613bc8a75e945ef483023f99d33176f835576bd08813df99aa', '689123'),
  ('4489576', 'f0349f6c0a49c8926bb59b120e71e3e4d31b8784a95ef378734a8cefd937788f', '4489576'),
  ('641293', 'c3c234dd755f14999ac21f32faf7c6bf839294ed6b2d3358feebb930f009cc23', '641293'),
  ('641182', '5fab97ac7be373d0907401609111db84cf89e4719c822efe4dbc93288c0e98ef', '641182'),
  ('3138999', '8a280914fb9923e23a1d7fa704fb7f662931770be974fb04c53109c9b412d8fb', '3138999'),
  ('707137', '488e2db6b5fdb54387891ff6b8bf538d9c3962c0ab95849b08f5487c954182a5', '707137'),
  ('721051', 'b1197e234e54927e0aed6257ae5508f58ea96149e9bdd4404de270882ee69bd1', '721051'),
  ('69094', 'c6918fb2e4ff4c4e5e4231ea90fa0cc866093090f1ffb935723342b47f07d0e1', '69094'),
  ('641145', '9ef6e151d4736743fef8cf8a0ad9df1acc174fb58d779b11b3266aa484363cce', '641145'),
  ('641194', '8f6cecbf3678606e9ee9a07c736cb9858c4c218d015fbffbf65637bc6923cb3a', '641194'),
  ('641338', '116072d82026501cab2211aaff8df31f7c63e2c015e05bec3f034673720682b1', '641338'),
  ('641670', 'd5c6436eb26c28e2745e2024feaba4e60752ea62e7d7d475cc913639dcb01e5e', '641670'),
  ('641684', '4de2740cf6c057d5c2edc6d92ee84722450b431ed3d3d9d4a3446a761d26cb4f', '641684'),
  ('641693', '787d4c5d8c7d9410e58a40337c1beaf63369dd1b1ee8ce0e44314715fdee4e2a', '641693'),
  ('641743', 'e04f3afc6589424d3999c695f11dfe56cba59478b42c8826b31ad01af1779d83', '641743'),
  ('641748', 'ff4628d922ed36fd14baccfe80e659c1b5db5be614a8458a2ac7a823c7999e50', '641748'),
  ('724702', '0400b38769bb09dfc6a0dd1cbeb7e983560d3552a2e0763a0f957597020840cf', '724702'),
  ('4488957', '7b94e53800644086149ede53eabf01f2c1b84c8a04c2d2e3176497c14ac35fad', '4488957'),
  ('641228', 'f36ae8e24890bf737e9b0719ae25aae78c1e4f3da6946fce563181ea2d924e77', '641228'),
  ('641755', '1ac6afd7d844c136faf2f720172d43d51ddeca75459f48299b20920df4b9dca5', '641755'),
  ('641774', 'd6241cd6adf4fb89df7782f49f7a485565490b34fd71ab5f093650597a072cf2', '641774'),
  ('641273', '98ad798de7b2d69329671c40cee316b5d46662f767725bd9aab60a50d673b9d6', '641273'),
  ('641188', 'debbebb899bf5a7d1482b1db95e3584e1fe6699995dc17f0b07844980f306664', '641188'),
  ('714763', 'dbce51a4304b1eacf7f97f71c1dbf516d159fc834ea8ff5bab45712ee620fcf9', '714763'),
  ('641318', 'cde4754ffceb42ee297048cb276c7bc610b4b63d7120ed4e40d007f853945ef5', '641318'),
  ('4486523', '38f27343b613bcb890f0b4b70c256a8b37276850b12d03ff8256aca685663d1e', '4486523'),
  ('641221', 'a4930c0091bc444e0b8601826eb7c211636a9e60654fa29beae0d59e2032b0cc', '641221'),
  ('728371', '1ffa332ab9f3469d819a82ef1ae4db93ea95c9618b1f58b7f5ff865d30b2afae', '728371'),
  ('3109187', '77ca74c271a7215a75411a64eac254a5f4a5687413146cacebe9e1879c49fa46', '3109187'),
  ('3138594', '04c32529639aa8b9e42fc3c1806af30b62da91674aa7943cfb9e87562bf61b02', '3138594'),
  ('4326913', '020ea924a45cf04f67583a13a9bfd5e87a07c17f27e9192e111af8dae9d29e58', '4326913'),
  ('4493443', '2b630f2ab324c5cd7632efc7e505d1a0d237a1264c8b4bf3369d000812e75db6', '4493443'),
  ('641274', 'cff3500651199009c10fc5f27e6efc3167ab275f2adf105ba579919f71e0d755', '641274'),
  ('641333', '836b844cd0b2aec645e07856d4a7461b49d7bf953fedbb84146193d95e6343da', '641333'),
  ('641762', '4293eaf31d0140e2d3c8ec63773c254d123990a3100ed0e37b76ddc00a2c14ae', '641762'),
  ('641809', '3e588504e3704fc4d4faaf4da7f9780d140081ad91047efba3e830e8e11079a7', '641809'),
  ('2458880', '5c7187b60e3436a021e13cdd26940ea96afdddacdff9ec4aadcaec3e93c8f52c', '2458880'),
  ('3110575', '0085f148d069c50591bdac60a59b52039f319516d4a34c4fef50ac6542cee348', '3110575'),
  ('3123069', '4b6751cadb643200a5fbc440b73adcc8fae219169d9dc9ad7dd6d626b2726670', '3123069'),
  ('3227835', 'b963b81a4461e3c891b520263335a3ca25bc83d6c5960d6146a5d378c1e5bb00', '3227835'),
  ('3159496', '660399b0ef9a06d4cca50bb1e10e8e57a707a9acdf02e928625e776d634e4b00', '3159496'),
  ('3166857', '2e6fb41b9453164d5ec57cf73cbfc78aff86f0f487b3bc691048c3ff18d85cb6', '3166857'),
  ('4484086', '0411676ea8ce78e35ca1f8ebe10b09c96de6e598ccc986c1b20325c62f157ad3', '4484086'),
  ('641165', 'b3fa9ce8eb572675d8245856f4c16507afa11545163543818e86a5fefb8afa90', '641165'),
  ('641176', 'b8ad209046869c66531d55a1575af2dc95c16c55170ec60219e37a36a2d8f512', '641176'),
  ('641289', '85da953e5ea59819905c5a47020e92c84c53d7a658ac6c9dd6cdb07d22d018c2', '641289'),
  ('641298', 'dcdb52effde9532c9981bf855e6445f202cdf4e624334fb3ebf6646ba1d9b7a1', '641298'),
  ('641308', '0f5fb95db6265d87a077c500084008c8d7ec11b0d43dc148b00a0dda438e75c7', '641308'),
  ('641313', 'fe7637825dfe907a8a3938b3f844a88552fb5a9041b40fbbde215c7dac10a4cc', '641313'),
  ('641326', '048ec4a9b655cbf5a4902d49b46fdad08d0153fe76eee8ade01aa8f66e26f085', '641326'),
  ('641329', 'af20399f2c01bbf1eae2c1d4d67df3f91fd97d69ad9534e221a6a3a8e773cd07', '641329'),
  ('641477', '9b9e1a34300ff26c668be99e4199c9f8f416adf11b39b382b98cf573dc55c83f', '641477'),
  ('641611', '004550de4dbdfa44183c9cc15524ab579a82f354c666c917cbe80587619985a8', '641611'),
  ('641613', '0b0ef6b0d95a75cd344892a6102d8dd027ef724cf299a8a3f39efc4491a2b131', '641613'),
  ('641620', '39eda54ea99da0316783565f8c8ba269bcdee7ce0d8e23d12f9f6f1d8c19cbe7', '641620'),
  ('641633', '15871b27030d2048c90975518a08d7757ee1d0de6287b3ef228bf66a2144ec6a', '641633'),
  ('641649', '4f4aa439f2192b171a13bc17d2f3271f6a65a5ec63ca75cdb288bb75bf485caf', '641649'),
  ('641689', '11cd62cc339b6cc2cf0b59d534e21da4154854b3db36875f228cc26ee6fd25ce', '641689'),
  ('641733', '2031607bd674a775d06c001a8a867c26122a850957b39f6541d80d34ec47a8f5', '641733'),
  ('641811', '686ae3281b55eb1ad9cd7c1f0f4bdc7edb4e996e0dd9261a2f9fd286a89a1130', '641811'),
  ('641824', '2a3469177c97e5f77a147dfbc806542cf6903f858c1ba517e14c5910e472b984', '641824'),
  ('641850', '78e81c0707a32acbfaab651fa04f4d0b5c1af01c4b2a6485b563e3a4884a40e1', '641850'),
  ('641884', 'bcf8920fb090fe4181d1155e49448906fa04d2986f5cfdd5980d560fcd72db52', '641884'),
  ('641886', '3e50e3d0bda6905e6972240672742a15df43e66616d470019baec3822ed6958a', '641886'),
  ('707822', 'e3b8f84e588904f9f3e7b4ec2c3e56bcd7fdee293d08ed706450fc76105c1763', '707822'),
  ('710371', '4a9172966b847e1df2ad799bcba9a45f8a466ff273c796fef011a8c16bdba28e', '710371'),
  ('725412', '2481a68f98f62b077d88d2e48ef562ac6b103eacb40a3ed4ddce0f20a3cecbb2', '725412'),
  ('731489', '704a2d81f5724241540f86ae7e8106e99fee398e7de63d284f6fc55ac3684a04', '731489'),
  ('756891', '087104d93ad2e7d69480137ea51effc62a2c276d24264474d631d5c4416612ea', '756891'),
  ('757362', '208cef9f0c521ad34f3716ebf6b2e24dd2df024ae63849cb01408239a7dccaae', '757362'),
  ('757669', '342c3505bd06f9fb547527f1550b865983696b6f615ef2f7bbcb7f3ce4cb114c', '757669'),
  ('774965', 'e448bc59cda5595365e2d385cc5998562a48340792abed5f7c2f2067e48d4063', '774965'),
  ('366979', 'b4cf7a8d7a79f0f1d17981c1a5fa2b069cbe07e1e92e26a62423e1af11428a32', '366979'),
  ('508325', 'bd487106a5782849604ea9da670919151a0c780e78dfca582a0f70b463fd0c99', '508325'),
  ('641328', '6b3392a51fc0ed9207728b568f038c829f6b1549845e542302f04b5a2be7fc72', '641328'),
  ('641614', 'eab807bfffc6c71b1eb39cd009d6aa6cc5a4d4f83c5e347d47b225c8508aa7b1', '641614'),
  ('641815', '5786af0190fe31f6049d2fcfe5c9fc475b0b7d092765246f39f83b1e5dae66ca', '641815'),
  ('641820', 'ac05cf95479a5016a57580c3b9216402759afaae6df301a3f9b238efd70ed681', '641820'),
  ('641917', 'f6674cfd183d7bf2e86244f3ce7e44646b05c37db1ccf8d6b40896cfc7adedff', '641917'),
  ('641922', 'd8c21f626f5bf7d59ba31c85939c2e2691e7a4510afad2d369bcef7c3e489744', '641922'),
  ('691122', 'f0440785ac01977f1c6d428acf984fa9c582bfdafa3db2141c2ada66c89d0080', '691122'),
  ('736279', 'bdf6c787b6269928908ed8906cca995c78ebfcb4de37f958a14f7726cdd5e859', '736279'),
  ('4519132', '228bc1e7df14a8044ada019fe89fba664b9957b4da20397f2cf168de57077032', '4519132'),
  ('4525208', '79fafee1cf330d8fc2150f5645eb587676a397c639f0926f283ab1d46dfee81a', '4525208'),
  ('3227924', '06147427a63a97f3ba95c853a99729d06912c5d84071d6ce1a19f8fdbe4d39a8', '3227924'),
  ('641245', '094f008df4520b14e8e525f0e519d3150a9cbd9854538e39faf2e481e2212ae2', '641245'),
  ('2395844', '97ee53d653f489c9ca82864d0413b7ff5518fff777c56db1796896d8481ff590', '2395844'),
  ('3065634', 'fad2249fcb7cd49e04908ff7deb618dab4211da91578258a965b96d36a882faa', '3065634'),
  ('612622', '7bda7f3883858138ea03e1695e633138e35cf842314d5aa1a796c212a103e229', '612622'),
  ('623597', 'bb59a953be96bce0b972a8aef1145053ffdeae258cc4367a3e5118fa41e37b73', '623597'),
  ('641226', '8c64e4c4cd96d2024da694e32177eb922cf3e0d6c770538b86879336388bf8ef', '641226'),
  ('641286', 'e199bfa5f1411a7ee7f9b7d3ca129873d907e36f998baeacc5fdf10e48bdac6d', '641286'),
  ('641319', 'ab8a8f39b6703b2eb61861dd96b168bdbf9d7815b1eb7d44eb30ec6dfd3ca32d', '641319'),
  ('641321', '29a1e727111efb00dae9c9ac13525293a458e4bac87b02be18b467627031cf08', '641321'),
  ('641646', 'a58feb3378a5670bcaed4b6844044d15e8835076e3cda67f929427b37416de08', '641646'),
  ('641738', 'b2da0c525a49ae0196f48b71cec23a66f5835daf3a253a949653adde4ef68d9b', '641738'),
  ('707942', '4209be0f2cc810c6dbe5253bd041a8b974b0661cfcc022a1e431373c95ca873d', '707942'),
  ('754531', 'c3716c7b3094674258c62f11d3b7f16614c562cd13f63165609e0486074756ee', '754531'),
  ('4495695', '79301a296854cbe9f41aab70e0825ce327b4483828f49002a60b37719de2f45b', '4495695'),
  ('641299', '1870e0c71b4115e9e73f1432f545c65f30dd7c4ff2c5874b5ff165525ce41f75', '641299'),
  ('641617', 'c62f5de1548bf2448ae9e7cf6d578ba9d1c4c18ceb8763959853538310058945', '641617'),
  ('641201', 'bb06c6c228f8c398161545175a0ea8cb1444cb3bd35896779291090e17623305', '641201'),
  ('641271', '69fcffcbd95c1e55d178488b52b98960d9518a2668dff2793c65bb80cb6b6748', '641271'),
  ('641853', '55a0dda0c6c305d8da404a84f1afd27e1e09fce5a608e6ab03c6cb8d6ea3d25f', '641853'),
  ('641658', '975430bea8ebf7eb802496d74d97715969df6db599d9a91aeec98efcdf322f34', '641658'),
  ('641715', '9f3e012f176fb0124efbe83e65624516c7c6c6ecca8441c23d77ff747b47e2f3', '641715'),
  ('4493368', '292b6dc41871c307fc68cab75385b6c0c3ffc95fe27deb0593f3a13c5511f8b8', '4493368'),
  ('643052', 'ede3b095c95e37a564565b1a10bbc2234b4b197e16e6e9506ec80c36469d72be', '643052'),
  ('757442', '660828226a940ccb06027649757756a57a8884aabf7c922d9bf6c88657e0b714', '757442'),
  ('641237', '7c8a523e6620c284a72916f65003e5596269bc8be9bb6638286c9bae79c51963', '641237'),
  ('641309', '3b57f69d54465786a5754d1febe624b5b73f81a5cb65e6af8a250ad482de4d9c', '641309'),
  ('641317', 'ee4bae042517310b5fac0af1bd0faa7473e75f3bcf3731416c0206398009db80', '641317'),
  ('641653', 'b3f16d1d9acc559a7afddb12b997b3f0683a9e4d24e3094081b71e3201d76090', '641653'),
  ('641771', '70bdaaa241d3d61550187282c23951a14b9601ee504f07993489372f50bd90a9', '641771'),
  ('641781', '481050ffc9e1cb4c5e13978d6065e04412d0ef3563378edffe7b74b899ba8802', '641781'),
  ('641789', '8549f6f910bbf56a39a0b9390824f82db38abb1084568a868a13bdebc5fde141', '641789'),
  ('641291', 'f66d260f863fdeb9bdc34cfd5b9fefa97de85991867ebfc37d0db0284fc9aef4', '641291'),
  ('641675', '8b88e16e97a91dd8267395776071975da17038122602fe2fa0e18305c3ce76cd', '641675'),
  ('641722', 'b6bb91514759b000de1c8ba4c2931557caadd0b1ab62ab1c10737ec2a5b910d0', '641722'),
  ('641739', '0d7e0134a270d0ec68ccb6c4c3dc4b409629fa20477ca12f36e1e5d9383fb6de', '641739'),
  ('3187564', '770664cf28b7083f4744ba91a05c60d34e15337446ba7cdcfcba0141fe925fed', '3187564'),
  ('601217', '0e9b30ea79145b53f0394276a1ddced0928f6de2ecd7b4f1052b1cbdd8195433', '601217'),
  ('641700', 'f8c13fdcc215d603b98845ab6a61dd7eb9befafcabba335e8379110cb85085e1', '641700'),
  ('641757', '62acf2bc8cc4706cd4eabcb1c992310a9de7de3d724f4329ea568c3a028dd48b', '641757'),
  ('760301', '76ab689f9b6c0fb1e2d7c05d015498e15326acaeeb6724190da1e7ed6e712370', '760301'),
  ('617939', '61ee83d783fda3913e90af203b8cc4a7b13e10a2e119a5536aa397626d0a06c8', '617939'),
  ('641316', '55419a6885c46597ddb6e3d498db473a2f0b3dc503a6535d7075039c51835e8c', '641316'),
  ('641740', '675daa931c54619340ee2b1f78e5f903d14bbbf66fac906988b7c4aa97cd6339', '641740'),
  ('641763', '76b17e76e1a45c3fe04729f410b2163cd5b526a9d118d14c1b1fdb84f06da404', '641763'),
  ('735015', '5050ae8f55db41242e10092af34ec1d3c1d686409f46a157db1a135f99b1579b', '735015'),
  ('3203304', '2064c1a11449cd26f18864a0aa38772b9b6be6e750f365602f1182ea9e98bf2d', '3203304'),
  ('18729', 'da0aa6012c4731b29c763cb73c4f5f83f5e0f37959ce966722f26ac8803d877e', '18729'),
  ('312892', '1539a1a2ad3f3455d1958b3ad4c9a3a1b21ad6d8878cc8bd4fd87c6354874dd0', '312892'),
  ('641149', 'd77fd4dc2205d9bdacf3d6ebdc33dc8c635031f2a6d9557ca0feaf371aa22e06', '641149'),
  ('641142', '6f8443034002d23432b44a6344f4a4e057e004fc4fa2abc7cbbdd6a670270ea4', '641142'),
  ('641180', 'd39bebad847f1b7ed1f98788aa6277dd87826a9d270a25f275b48df5e2c31df5', '641180'),
  ('641626', '422b57e01fbcda4d9da51fda4ecf6c975751a1e96fbf9109f592a4c48400b64e', '641626'),
  ('641707', '1d02288574c27d5456c20b3737b53237ac6f67f6000a278bd3af3d6326146543', '641707'),
  ('641812', '88c2371e1b9fe6e20990746f010a2751ad9ad90125dcd67f47a77db3d78d6d0f', '641812'),
  ('4525209', 'cf71bc58a36481dbcd18b1d886129bd9773dcf32faae98c318757052dfa7b47f', '4525209'),
  ('641284', 'ac5c4fe42d21e3ee62fa8849231a983fc105fa4044e56d52492901c1cb6a9afa', '641284'),
  ('641522', '313188aad1d982d630e05835406f620ffde9fad13c35f45912c6500404c1ee26', '641522'),
  ('641712', '88ca0a40dc39a5de9045224c84cae8d425823be099e730a53cb23d9a80cf1519', '641712'),
  ('641713', '1d707f9d44ea2debab17609168cffed08ea3b3a612ae8fba862ada55f1dc7b29', '641713'),
  ('641734', 'f9eab0b2251e45ce3ef1f031a9def7c75f3c4e1d2dd3536927d4d05c419da457', '641734'),
  ('641911', 'a90b68994820c8d201feb8a7abb99ab27a77ba3770f175400dd0794680eb9fc8', '641911'),
  ('641770', '40d22cb503d8deb4524a6f85cd39551ef1fa60b1e1ee07b8776507ddb1e0c79c', '641770'),
  ('266495', 'f3491f1cc2d417993313d3cb887e6a6388dc0c4aea7d61271a2197930b117386', '266495'),
  ('641750', '3e634f0a07f8913b10cca40ae0d13464bd62456c2de108d0405349e356367119', '641750'),
  ('246043', '67f20e339fb475d5335f72117454f7a329ddbd31d0fb20f2b1ffc1603e41fa6e', '246043'),
  ('641147', '26032f64a9112b871d80e7d36c97bb65914eca807f70ba9b91dc013581b42943', '641147'),
  ('641178', '905ee65750fda20a44fda60c1ac2fc0fadfde71b738f074bbe49d7f4297dab4d', '641178'),
  ('641184', '7e0251a2222a4ef09050da9929d1e08642e08d6188491efb04618755f0a5d203', '641184'),
  ('641779', 'e51753ad325c522fbb75ad21bf2f6f1354f8c7dff4ee4d2289000279431aa247', '641779'),
  ('641322', '2c1e8cf141d1b63369595138a3b0990e90d8dfb92519555cde0aa3a471541809', '641322'),
  ('641327', 'ca46f4b529752f9e88c75ab6255cf778e2935f2939d69abc933ddf915a65c867', '641327'),
  ('641621', 'ba67f749e1653126534cd28bdfcdfe8877aef6d1b40acb63359ebbd81da91f1b', '641621'),
  ('641628', '3ff7bc03a662c40865ff09fc1e1ecf453b23dc1de88a191c18195ceb0fc3d390', '641628'),
  ('641636', '6f707b206248d806eb6e6d05f2e676409b5da7f15e440581b39922631eb5cab5', '641636'),
  ('641641', '966a573dc3b1d9cb0b841dca095c10e327d38f5bd0df66961c5168006ea9905d', '641641'),
  ('641783', '3dd8e0b876d2c4c7d27aa611a4d8bf69c2fad95661a9f25296c707620f0941f3', '641783'),
  ('641312', '0bfc4c7de0950f21784292989e3ed6253ba889d9db0726cd0bfaa0e5e16ba91c', '641312'),
  ('708140', 'b0376873f41a4df61f0a76d08341c56035db5b44c32f0d09ef7ed0b62d2fa9e9', '708140'),
  ('3252557', '151a383e60553b7d499b3c1a1eae1f34a70f65237dd93ea115a0767f12e3eedb', '3252557'),
  ('3252689', '126651714fec77790d3e61ed1f1130ad7ebc865d966378808f27d5a7f5e52544', '3252689')
ON DUPLICATE KEY UPDATE
  password = VALUES(password);
