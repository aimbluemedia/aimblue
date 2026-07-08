-- ============================================================
--  Social Media Logins — Database Tables
--  Run in phpMyAdmin on your existing database
-- ============================================================

CREATE TABLE IF NOT EXISTS social_logins (
  id              VARCHAR(36)   NOT NULL,
  platform        ENUM('facebook','instagram','youtube','tiktok','reddit','pinterest') NOT NULL,
  title           VARCHAR(255)  NOT NULL COMMENT 'Custom label e.g. "Storm Facebook Main"',
  channel_url     TEXT          DEFAULT NULL COMMENT 'Link to the channel/page/profile',
  username        VARCHAR(255)  DEFAULT NULL,
  password_enc    TEXT          DEFAULT NULL COMMENT 'AES-256-CBC encrypted password',
  notes           TEXT          DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_platform (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_login_companies (
  login_id    VARCHAR(36) NOT NULL,
  company_id  VARCHAR(36) NOT NULL,
  PRIMARY KEY (login_id, company_id),
  KEY idx_company (company_id),
  CONSTRAINT fk_slc_login   FOREIGN KEY (login_id)   REFERENCES social_logins (id) ON DELETE CASCADE,
  CONSTRAINT fk_slc_company FOREIGN KEY (company_id) REFERENCES companies     (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
