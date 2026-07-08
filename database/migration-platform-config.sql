-- ============================================================
--  Migration: Per-platform posting config for companies
--  Run in phpMyAdmin
-- ============================================================

ALTER TABLE companies
  ADD COLUMN platform_config TEXT DEFAULT NULL
  COMMENT 'JSON: {platform: {days:[], social_login_id: ""}}';

-- Also add posting_days column per platform in social_login_companies
ALTER TABLE social_login_companies
  ADD COLUMN posting_days VARCHAR(50) DEFAULT NULL
  COMMENT 'CSV of days e.g. Mon,Wed,Fri';

-- Verify
SHOW COLUMNS FROM companies LIKE 'platform_config';
SHOW COLUMNS FROM social_login_companies;
