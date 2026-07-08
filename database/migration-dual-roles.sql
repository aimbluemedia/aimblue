-- ============================================================
--  Migration: Users dual-role support
--  Run this in phpMyAdmin on your existing database
-- ============================================================

-- 1. Change role column to VARCHAR to support comma-separated roles
--    e.g. 'content_manager,sales_person'
ALTER TABLE users
  MODIFY COLUMN role VARCHAR(100) NOT NULL DEFAULT 'content_manager';

-- 2. Add separate CM and SP person links (replaces single person_id)
ALTER TABLE users
  ADD COLUMN cm_person_id VARCHAR(36) DEFAULT NULL
    COMMENT 'Links to people table for Content Manager role'
    AFTER role,
  ADD COLUMN sp_person_id VARCHAR(36) DEFAULT NULL
    COMMENT 'Links to people table for Sales Person role'
    AFTER cm_person_id;

-- 3. Copy existing person_id into the correct column based on role
UPDATE users SET cm_person_id = person_id WHERE role = 'content_manager' AND person_id IS NOT NULL;
UPDATE users SET sp_person_id = person_id WHERE role = 'sales_person'    AND person_id IS NOT NULL;

-- 4. Add foreign key constraints (optional — skip if you get errors)
-- ALTER TABLE users ADD CONSTRAINT fk_user_cm FOREIGN KEY (cm_person_id) REFERENCES people(id) ON DELETE SET NULL;
-- ALTER TABLE users ADD CONSTRAINT fk_user_sp FOREIGN KEY (sp_person_id) REFERENCES people(id) ON DELETE SET NULL;

-- 5. Verify result
SELECT id, username, role, cm_person_id, sp_person_id FROM users;
