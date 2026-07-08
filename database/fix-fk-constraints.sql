-- ============================================================
--  Fix: Remove foreign key constraints from companies table
--  These constraints cause errors when CM/SP IDs don't exist yet
--  Run this in phpMyAdmin on your database
-- ============================================================

-- Drop FK constraints (names may vary — run SHOW CREATE TABLE companies to check)
ALTER TABLE companies DROP FOREIGN KEY IF EXISTS fk_co_cm;
ALTER TABLE companies DROP FOREIGN KEY IF EXISTS fk_co_sp;
ALTER TABLE companies DROP FOREIGN KEY IF EXISTS companies_ibfk_1;
ALTER TABLE companies DROP FOREIGN KEY IF EXISTS companies_ibfk_2;

-- Also drop any other FK on content_manager_id / sales_person_id
-- (run this to find all constraint names if above didn't work)
-- SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_NAME='companies' AND TABLE_SCHEMA=DATABASE()
-- AND REFERENCED_TABLE_NAME='people';

-- Verify result
SHOW CREATE TABLE companies;
