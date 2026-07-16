-- ============================================================
--  Adds a display colour to users and people (avatar colour,
--  picked in the Users page and shown on every page).
--
--  NOTE: api.php applies this automatically on first use
--  (ensure_color_columns), so running it by hand is optional.
--  Kept here for reference / fresh installs.
-- ============================================================

ALTER TABLE users  ADD COLUMN color VARCHAR(7) DEFAULT NULL;
ALTER TABLE people ADD COLUMN color VARCHAR(7) DEFAULT NULL;
