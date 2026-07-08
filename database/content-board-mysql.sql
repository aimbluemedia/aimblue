-- ============================================================
--  CONTENT BOARD — MySQL Database
--  Compatible with shared hosting (cPanel / Hostinger / etc.)
--  Import via phpMyAdmin or: mysql -u user -p existing_db < this_file.sql
--  No DROP DATABASE or CREATE DATABASE needed.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ============================================================
--  DROP existing tables (clean reinstall)
-- ============================================================
DROP TABLE IF EXISTS revenue_snapshots;
DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS person_companies;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS people;
DROP TABLE IF EXISTS settings;

-- ============================================================
--  TABLE: people
--  Content managers and sales people
-- ============================================================
CREATE TABLE people (
  id          VARCHAR(36)   NOT NULL,
  role        ENUM('content_manager','sales_person') NOT NULL,
  name        VARCHAR(255)  NOT NULL,
  email       VARCHAR(255)  DEFAULT NULL,
  notes       TEXT          DEFAULT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE  KEY uq_email (email),
  KEY     idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: companies
-- ============================================================
CREATE TABLE companies (
  id                  VARCHAR(36)      NOT NULL,
  name                VARCHAR(255)     NOT NULL,
  color               VARCHAR(7)       NOT NULL DEFAULT '#6c47ff',
  content_manager_id  VARCHAR(36)      DEFAULT NULL,
  sales_person_id     VARCHAR(36)      DEFAULT NULL,
  monthly_posts       INT UNSIGNED     DEFAULT NULL,
  fee                 DECIMAL(10,2)    DEFAULT NULL,
  fee_sp_pct          DECIMAL(5,2)     NOT NULL DEFAULT 40.00,
  fee_cm_pct          DECIMAL(5,2)     NOT NULL DEFAULT 40.00,
  fee_sm_pct          DECIMAL(5,2)     NOT NULL DEFAULT 20.00,
  payment_date        TINYINT UNSIGNED DEFAULT NULL COMMENT 'Day of month 1-31',
  posting_days        VARCHAR(100)     DEFAULT NULL COMMENT 'Comma separated: Mon,Tue,Wed,Thu,Fri,Sat,Sun',
  created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm (content_manager_id),
  KEY idx_sp (sales_person_id),
  CONSTRAINT fk_co_cm FOREIGN KEY (content_manager_id) REFERENCES people (id) ON DELETE SET NULL,
  CONSTRAINT fk_co_sp FOREIGN KEY (sales_person_id)    REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: person_companies
--  Many-to-many: one person can work across multiple companies
-- ============================================================
CREATE TABLE person_companies (
  person_id   VARCHAR(36) NOT NULL,
  company_id  VARCHAR(36) NOT NULL,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, company_id),
  KEY idx_company (company_id),
  CONSTRAINT fk_pc_person  FOREIGN KEY (person_id)  REFERENCES people    (id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: posts
-- ============================================================
CREATE TABLE posts (
  id          VARCHAR(36)  NOT NULL,
  company_id  VARCHAR(36)  NOT NULL,
  title       VARCHAR(500) NOT NULL,
  platform    ENUM('blog','facebook','instagram','tiktok','youtube','reddit','pinterest') NOT NULL,
  status      ENUM('scheduled','published') NOT NULL DEFAULT 'scheduled',
  post_date   DATE         DEFAULT NULL,
  assignee    VARCHAR(255) DEFAULT NULL COMMENT 'Content manager name',
  post_url    TEXT         DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_company   (company_id),
  KEY idx_platform  (platform),
  KEY idx_status    (status),
  KEY idx_post_date (post_date),
  KEY idx_co_month  (company_id, post_date),
  CONSTRAINT fk_post_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: settings
-- ============================================================
CREATE TABLE settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT         DEFAULT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: revenue_snapshots
--  Monthly fee split history per company
-- ============================================================
CREATE TABLE revenue_snapshots (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  company_id     VARCHAR(36)   NOT NULL,
  snapshot_month DATE          NOT NULL COMMENT 'First day of month e.g. 2026-05-01',
  total_fee      DECIMAL(10,2) NOT NULL,
  sp_pct         DECIMAL(5,2)  NOT NULL,
  cm_pct         DECIMAL(5,2)  NOT NULL,
  sm_pct         DECIMAL(5,2)  NOT NULL,
  sp_amount      DECIMAL(10,2) NOT NULL,
  cm_amount      DECIMAL(10,2) NOT NULL,
  sm_amount      DECIMAL(10,2) NOT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_snapshot (company_id, snapshot_month),
  KEY idx_month (snapshot_month),
  CONSTRAINT fk_rs_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  VIEWS
-- ============================================================

CREATE OR REPLACE VIEW v_company_summary AS
SELECT
  c.id,
  c.name,
  c.color,
  c.monthly_posts,
  c.fee,
  c.fee_sp_pct,
  c.fee_cm_pct,
  c.fee_sm_pct,
  c.payment_date,
  c.posting_days,
  cm.name                                   AS content_manager,
  sp.name                                   AS sales_person,
  ROUND(c.fee * c.fee_sp_pct / 100, 2)      AS sp_earnings,
  ROUND(c.fee * c.fee_cm_pct / 100, 2)      AS cm_earnings,
  ROUND(c.fee * c.fee_sm_pct / 100, 2)      AS sm_earnings,
  COUNT(p.id)                               AS total_posts,
  SUM(p.status = 'published')               AS published,
  SUM(p.status = 'scheduled')               AS scheduled
FROM companies c
LEFT JOIN people cm ON cm.id = c.content_manager_id
LEFT JOIN people sp ON sp.id = c.sales_person_id
LEFT JOIN posts  p  ON p.company_id = c.id
GROUP BY
  c.id, c.name, c.color, c.monthly_posts, c.fee,
  c.fee_sp_pct, c.fee_cm_pct, c.fee_sm_pct,
  c.payment_date, c.posting_days, cm.name, sp.name;

CREATE OR REPLACE VIEW v_platform_monthly AS
SELECT
  p.company_id,
  c.name                                    AS company_name,
  p.platform,
  DATE_FORMAT(p.post_date, '%Y-%m-01')      AS month,
  COUNT(*)                                  AS total,
  SUM(p.status = 'published')               AS published,
  SUM(p.status = 'scheduled')               AS scheduled
FROM posts p
JOIN companies c ON c.id = p.company_id
WHERE p.post_date IS NOT NULL
GROUP BY p.company_id, c.name, p.platform, DATE_FORMAT(p.post_date, '%Y-%m-01');

CREATE OR REPLACE VIEW v_team_earnings AS
SELECT
  ppl.id                                    AS person_id,
  ppl.name,
  ppl.role,
  ppl.email,
  COUNT(DISTINCT pc.company_id)             AS num_companies,
  SUM(CASE ppl.role
        WHEN 'content_manager' THEN ROUND(c.fee * c.fee_cm_pct / 100, 2)
        WHEN 'sales_person'    THEN ROUND(c.fee * c.fee_sp_pct / 100, 2)
        ELSE 0
      END)                                  AS total_monthly_earnings,
  SUM(IFNULL(c.monthly_posts, 0))           AS total_monthly_posts
FROM people ppl
JOIN person_companies pc ON pc.person_id  = ppl.id
JOIN companies        c  ON c.id          = pc.company_id
GROUP BY ppl.id, ppl.name, ppl.role, ppl.email;

CREATE OR REPLACE VIEW v_posting_today AS
SELECT
  c.id                                      AS company_id,
  c.name,
  c.color,
  cm.name                                   AS content_manager,
  sp.name                                   AS sales_person,
  c.monthly_posts,
  c.fee
FROM companies c
LEFT JOIN people cm ON cm.id = c.content_manager_id
LEFT JOIN people sp ON sp.id = c.sales_person_id
WHERE c.posting_days IS NULL
   OR FIND_IN_SET(
        ELT(DAYOFWEEK(CURDATE()),'Sun','Mon','Tue','Wed','Thu','Fri','Sat'),
        c.posting_days
      ) > 0;

CREATE OR REPLACE VIEW v_revenue_totals AS
SELECT
  COUNT(*)                                  AS total_companies,
  SUM(fee)                                  AS total_monthly_revenue,
  SUM(ROUND(fee * fee_sp_pct / 100, 2))     AS total_sp_earnings,
  SUM(ROUND(fee * fee_cm_pct / 100, 2))     AS total_cm_earnings,
  SUM(ROUND(fee * fee_sm_pct / 100, 2))     AS total_sm_earnings
FROM companies;

-- ============================================================
--  STORED PROCEDURES
-- ============================================================

DROP PROCEDURE IF EXISTS sp_get_posts_by_month;
DROP PROCEDURE IF EXISTS sp_snapshot_revenue;

DELIMITER $$

CREATE PROCEDURE sp_get_posts_by_month(
  IN p_company_id VARCHAR(36),
  IN p_year       INT,
  IN p_month      INT
)
BEGIN
  SELECT id, title, platform, status, post_date, assignee, post_url
  FROM posts
  WHERE company_id = p_company_id
    AND (p_year  IS NULL OR YEAR(post_date)  = p_year)
    AND (p_month IS NULL OR MONTH(post_date) = p_month)
  ORDER BY post_date, platform;
END$$

CREATE PROCEDURE sp_snapshot_revenue(IN p_month DATE)
BEGIN
  INSERT INTO revenue_snapshots
    (company_id, snapshot_month, total_fee, sp_pct, cm_pct, sm_pct, sp_amount, cm_amount, sm_amount)
  SELECT
    id, p_month, fee, fee_sp_pct, fee_cm_pct, fee_sm_pct,
    ROUND(fee * fee_sp_pct / 100, 2),
    ROUND(fee * fee_cm_pct / 100, 2),
    ROUND(fee * fee_sm_pct / 100, 2)
  FROM companies
  WHERE fee IS NOT NULL
  ON DUPLICATE KEY UPDATE
    total_fee = VALUES(total_fee),
    sp_amount = VALUES(sp_amount),
    cm_amount = VALUES(cm_amount),
    sm_amount = VALUES(sm_amount);
END$$

DELIMITER ;

-- ============================================================
--  DEFAULT SETTINGS
-- ============================================================
INSERT INTO settings (setting_key, setting_value) VALUES
  ('app_version',    '1.0.0'),
  ('app_name',       'Content Board'),
  ('currency',       'USD'),
  ('default_fee_sp', '40'),
  ('default_fee_cm', '40'),
  ('default_fee_sm', '20')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
--  SAMPLE DATA
-- ============================================================

INSERT INTO people (id, role, name, email, notes) VALUES
  ('cm_jim',  'content_manager', 'Jim Emma', 'jim@contentboard.com',  'Blog, Instagram, TikTok specialist'),
  ('cm_emma', 'content_manager', 'Emma',     'emma@contentboard.com', 'Facebook and Pinterest specialist'),
  ('sp_neve', 'sales_person',    'Neve',     'neve@contentboard.com', 'Senior account manager'),
  ('sp_alex', 'sales_person',    'Alex',     'alex@contentboard.com', 'Junior account manager');

INSERT INTO companies
  (id, name, color, content_manager_id, sales_person_id, monthly_posts, fee, fee_sp_pct, fee_cm_pct, fee_sm_pct, payment_date, posting_days)
VALUES
  ('co_seo',   'SEO Grower',      '#6c47ff', 'cm_jim',  'sp_neve', 56, 300.00, 40, 40, 20,  1, 'Mon,Tue,Wed,Thu,Fri'),
  ('co_storm', 'Storm',           '#0891b2', 'cm_emma', 'sp_neve', 56, 450.00, 40, 40, 20,  2, 'Mon,Wed,Fri,Sat'),
  ('co_blue',  'Blue Wave Media', '#16a34a', 'cm_jim',  'sp_alex', 28, 550.00, 40, 40, 20, 15, 'Tue,Thu,Sat');

INSERT INTO person_companies (person_id, company_id) VALUES
  ('cm_jim',  'co_seo'),
  ('cm_jim',  'co_blue'),
  ('cm_emma', 'co_storm'),
  ('sp_neve', 'co_seo'),
  ('sp_neve', 'co_storm'),
  ('sp_alex', 'co_blue');

INSERT INTO posts (id, company_id, title, platform, status, post_date, assignee) VALUES
  ('p001','co_seo','How to grow your business in 2026','blog','published','2026-05-01','Jim Emma'),
  ('p002','co_seo','Top 10 SEO tips for this year','blog','scheduled','2026-05-08','Jim Emma'),
  ('p003','co_seo','Content strategy deep dive','blog','scheduled','2026-05-15','Jim Emma'),
  ('p004','co_seo','Weekly business update','facebook','published','2026-05-02','Jim Emma'),
  ('p005','co_seo','Behind the scenes','facebook','scheduled','2026-05-09','Jim Emma'),
  ('p006','co_seo','Customer success story','facebook','scheduled','2026-05-16','Jim Emma'),
  ('p007','co_seo','Summer collection drop','instagram','published','2026-05-03','Jim Emma'),
  ('p008','co_seo','New arrivals','instagram','scheduled','2026-05-10','Jim Emma'),
  ('p009','co_seo','Meet the team','instagram','scheduled','2026-05-17','Jim Emma'),
  ('p010','co_seo','Day in the life','tiktok','scheduled','2026-05-05','Jim Emma'),
  ('p011','co_seo','Quick tip Tuesday','tiktok','scheduled','2026-05-12','Jim Emma'),
  ('p012','co_seo','Product demo','tiktok','scheduled','2026-05-19','Jim Emma'),
  ('p013','co_seo','Full tutorial walkthrough','youtube','scheduled','2026-05-06','Jim Emma'),
  ('p014','co_seo','Monthly Q&A session','youtube','scheduled','2026-05-20','Jim Emma'),
  ('p015','co_seo','Industry trends discussion','reddit','scheduled','2026-05-07','Jim Emma'),
  ('p016','co_seo','Tools we use daily','reddit','scheduled','2026-05-14','Jim Emma'),
  ('p017','co_seo','Mood board May 2026','pinterest','scheduled','2026-05-04','Jim Emma'),
  ('p018','co_seo','Top picks this month','pinterest','scheduled','2026-05-11','Jim Emma'),
  ('p101','co_storm','Why blogging still works','blog','published','2026-05-01','Emma'),
  ('p102','co_storm','Content planning for Q3','blog','scheduled','2026-05-08','Emma'),
  ('p103','co_storm','Customer spotlight','facebook','published','2026-05-02','Emma'),
  ('p104','co_storm','Product launch announcement','facebook','scheduled','2026-05-09','Emma'),
  ('p105','co_storm','Weekend vibes','instagram','scheduled','2026-05-03','Emma'),
  ('p106','co_storm','Staff pick of the week','instagram','scheduled','2026-05-10','Emma'),
  ('p107','co_storm','Before and after reveal','tiktok','scheduled','2026-05-05','Emma'),
  ('p108','co_storm','Trending sounds this week','tiktok','scheduled','2026-05-12','Emma'),
  ('p109','co_storm','Brand story episode 1','youtube','scheduled','2026-05-06','Emma'),
  ('p110','co_storm','Product review series','youtube','scheduled','2026-05-20','Emma'),
  ('p111','co_storm','Ask me anything CEO edition','reddit','scheduled','2026-05-07','Emma'),
  ('p112','co_storm','Community update May','reddit','scheduled','2026-05-14','Emma'),
  ('p113','co_storm','Seasonal inspiration board','pinterest','scheduled','2026-05-04','Emma'),
  ('p114','co_storm','Design trends May 2026','pinterest','scheduled','2026-05-11','Emma');

-- ============================================================
--  QUICK REFERENCE QUERIES
-- ============================================================
/*
  SELECT * FROM v_company_summary;
  SELECT * FROM v_revenue_totals;
  SELECT * FROM v_posting_today;
  SELECT * FROM v_team_earnings ORDER BY total_monthly_earnings DESC;
  SELECT * FROM v_platform_monthly WHERE company_id = 'co_seo' AND month = '2026-05-01';
  CALL sp_get_posts_by_month('co_seo', 2026, 5);
  CALL sp_snapshot_revenue('2026-05-01');
*/

-- ============================================================
--  USER AUTHENTICATION TABLES (add to existing schema)
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  id            VARCHAR(36)     NOT NULL,
  username      VARCHAR(100)    NOT NULL,
  password_hash VARCHAR(255)    NOT NULL,
  role          ENUM('admin','content_manager','sales_person') NOT NULL DEFAULT 'content_manager',
  person_id     VARCHAR(36)     DEFAULT NULL COMMENT 'Links to people table for CM/SP roles',
  full_name     VARCHAR(255)    NOT NULL,
  email         VARCHAR(255)    DEFAULT NULL,
  last_login    DATETIME        DEFAULT NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE  KEY uq_username (username),
  KEY idx_role (role),
  KEY idx_person (person_id),
  CONSTRAINT fk_user_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user (password: Admin@2026 — CHANGE THIS IMMEDIATELY)
INSERT IGNORE INTO users (id, username, password_hash, role, full_name, email) VALUES (
  'usr_admin',
  'admin',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- TEMP HASH: run reset-password.php to set a real password
  'admin',
  'Administrator',
  'admin@contentboard.com'
);
