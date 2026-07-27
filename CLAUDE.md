# Content Board — Project Context for Claude Code

## Project Summary
Multi-company social media content tracking dashboard. PHP 8.x + MySQL, vanilla JS, Bootstrap 5 CDN. No frameworks, no build step. Hosted on Hostinger shared hosting.

## Current Status
The app loads and most features work. The former companies/people display bug
is FIXED: the JS init called `.split(',')` on `posting_days`, which both the
PHP loader and api.php deliver as an already-exploded array — the resulting
TypeError killed the whole init before anything rendered. The init was rebuilt
(2026-07) with shape-tolerant normalisers, per-row error isolation, visible
error banners instead of silent catches, and a DOMContentLoaded guard.
Verified end-to-end in Chromium against a real MySQL database.

## Repo Layout
The app lives at the REPO ROOT so Hostinger's hPanel Git deployment (which
clones the whole repo into public_html) works with no restructuring. The
root `.htaccess` blocks web access to every non-app path — never delete it.
```
index.php                    Main dashboard (single-page app — all UI + JS)
api.php                      REST JSON API — all DB reads/writes
auth.php                     Session helpers
config.php                   DB config wizard + db_connect()
login.php                    Login page
.htaccess                    Webroot guard — blocks setup/, database/, docs/, SQL, git internals

setup/                       Blocked by .htaccess; excluded from FTP deploys.
  setadmin.php               One-time admin setup — copy next to index.php, run, DELETE
  reset-password.php         One-time password reset tool — same drill

database/                    SQL — run in phpMyAdmin (blocked from web access)
  content-board-mysql.sql    Full schema + sample data (safe to re-run)
  migration-dual-roles.sql   Adds cm_person_id / sp_person_id to users
  migration-social-logins.sql  Creates social_logins tables
  migration-platform-config.sql  Adds platform_config JSON column to companies
  migration-user-colors.sql  Adds color to users/people (api.php auto-applies this one)
  fix-fk-constraints.sql     Drops FK constraints on companies (run this!)

legacy/                      Original Claude artifact prototype (blocked from web access)
.github/workflows/deploy.yml Alternative FTPS auto-deploy (push to main)
docs/SETUP-HOSTINGER.md      GitHub↔Hostinger connection guide

db.config.php                Generated on the server by config.php (gitignored)
```

## Database Schema
```sql
companies          id, name, color, content_manager_id*, sales_person_id*, fee,
                   fee_sp_pct, fee_cm_pct, fee_sm_pct, monthly_posts, payment_date,
                   posting_days (CSV), platform_config (JSON), created_at, updated_at

people             id, name, email, role (content_manager|sales_person), notes
person_companies   person_id, company_id

posts              id, company_id, title, platform, status, post_date, assignee, post_url

users              id, username, password_hash, role (comma-sep: admin,content_manager,sales_person),
                   cm_person_id, sp_person_id, full_name, email, is_active, last_login

social_logins      id, platform, title, channel_url, username, password_enc (AES-256-CBC),
                   notes (posting software), created_at
social_login_companies  login_id, company_id, posting_days

* FK constraints on companies.content_manager_id / sales_person_id should be DROPPED.
  Run fix-fk-constraints.sql in phpMyAdmin.
```

## Architecture Decisions

### Single merged <script> block
All JS lives in ONE `<script>` block at the bottom of index.php. Multiple script blocks
caused cross-scope `Identifier already declared` SyntaxErrors. Never split them.

### PHP-direct data loading
Companies and people are loaded from MySQL in PHP at render time and JSON-encoded into JS:
```js
var phpCos = <?= json_encode($initial_companies) ?>;
var phpPpl = <?= json_encode($initial_people) ?>;
```
A background `apiCall()` sync runs after. This bypasses AJAX session issues on initial load.

### Field naming duality
MySQL uses snake_case (`content_manager_id`, `fee_sp_pct`).
JS uses camelCase (`contentManagerId`, `feeSP`).
The init block normalises both. Every field has both forms on the object.

### Encrypted passwords
Social login passwords stored AES-256-CBC via `encrypt_pw()`/`decrypt_pw()` in api.php.
Key derived from DB_NAME. Reveal endpoint: `api.php?action=reveal_password&id=X`.

### Dual roles
Users can be both CM and SP. `users.role` is VARCHAR with comma-separated values.
`cm_person_id` and `sp_person_id` are separate FK columns.

### Platform config (per-company)
Edit Company modal has per-platform posting days + social login selector.
Stored as JSON in `companies.platform_config`.
Format: `{"facebook":{"days":["Mon","Wed"],"social_login_id":"sl_xxx"}, ...}`

## API Endpoints
All return `{ok: bool, data: any}` or `{ok: false, error: string}`.

| Action | Method | Description |
|--------|--------|-------------|
| companies | GET | All companies (filtered by session for CM/SP) |
| save_company | POST | Insert/update company |
| people | GET | All people |
| save_person | POST | Insert/update person |
| delete_person | POST | Delete person |
| posts | GET | Posts for company (?id=co_id) |
| save_post | POST | Insert/update post |
| delete_post | POST | Delete post |
| users | GET | All users (admin only) |
| save_user | POST | Insert/update user |
| delete_user | POST | Delete user |
| social_logins | GET | All social logins (filtered for CM/SP) |
| save_social_login | POST | Insert/update social login |
| delete_social_login | POST | Delete social login |
| reveal_password | GET | Decrypt and return password (?id=login_id) |
| content_ideas | GET | Ideas for company (?id=co_id), last-used first |
| generate_idea | POST | Claude API generates a new idea (admin/CM, own companies) |
| use_idea | POST | Mark idea used (fills Add Post title) |
| delete_idea | POST | Delete idea |
| claude_key_status | GET | Is a Claude API key configured (admin) |
| save_claude_key | POST | Store Claude API key, AES-encrypted (admin) |

Content Ideas: "Generate Content Idea" button in the Add Post popup
(admin+CM) + idea suggestion chips below it. Per-company `content_prompt`
(companies column, edited in the company modal) + all previous ideas feed a
claude-opus-5 call (raw cURL in api.php — no Composer on shared hosting).
The Claude API key is entered on config.php ("API settings", admin session
required), stored AES-encrypted in settings. Ideas are marked used when the
post is saved. The content_ideas table and content_prompt column
self-migrate on first use.

## Roles & Access
| Feature | Admin | Content Manager | Sales Person |
|---------|-------|-----------------|--------------|
| All companies | ✓ | Own only | Own only |
| Add/Edit/Delete company | ✓ | ✗ | ✗ |
| Add/Edit posts | ✓ | ✓ | ✗ |
| Users page | ✓ | ✗ | ✗ |
| Social logins (view) | ✓ | Own companies | Own companies |
| Social logins (edit) | ✓ | ✗ | ✗ |

## What Claude Code Should Fix/Build

### Priority 1 — Stability
- [x] Rebuild index.php JS init to be 100% reliable (no silent failures) —
      root cause was `.split(',')` on an array; init rebuilt with normalisers,
      per-row error isolation, error banners, DOMContentLoaded guard, and a
      background sync that reports failures with a Retry link
- [x] Add proper error handling in api.php — catches Throwable (not just
      PDOException) so fatals return JSON; 401 JSON instead of login redirect;
      405 for wrong-method requests instead of an empty 200; errors logged via
      error_log; plus company filtering for CM/SP on `companies`/`posts` and a
      fixed multi-company access check on `reveal_password`
- [ ] Remove the debug files (debug.php, fix.php, recover.php etc.) from server
      — MANUAL: delete via hPanel File Manager (they're gitignored, so deploys
      will never re-add them)
- [ ] Run fix-fk-constraints.sql on the database — MANUAL: run in phpMyAdmin

### Priority 2 — Architecture
- [ ] Split index.php into separate files:
  - `public/js/app.js` — all JavaScript
  - `partials/modals.php` — modal HTML
  - `partials/sidebar.php` — sidebar HTML
  - `partials/topbar.php` — topbar HTML
- [ ] Move db.config.php above webroot
- [ ] Add CSRF tokens to all POST requests
- [ ] Input validation/sanitization on API endpoints

### Priority 3 — Features
- [ ] Dashboard calendar properly shows scheduled posts on their dates
- [ ] Click a platform card → opens Add Post pre-filled with platform + social login info
- [ ] Email notifications for overdue posts
- [ ] CSV/PDF export of posts

## Environment
- PHP 8.3 on Hostinger shared hosting
- MySQL — database: u312278121_wpi
- No SSH, no Composer, no npm
- Deploys: Hostinger hPanel Git deployment clones the repo straight into
  `public_html/` (the layout is designed for this — see .htaccess). The
  GitHub Actions FTPS workflow (push to `main`) is an alternative path.
- OpCache enabled (run opcache-reset.php after deploys, then delete it)

## Running Locally
1. PHP 8.x + MySQL
2. Point web server at the repo root (or run `php -S localhost:8000` in it)
3. Visit config.php → enter DB credentials
4. Import database/content-board-mysql.sql
5. Run all database/migration-*.sql files
6. Run database/fix-fk-constraints.sql
7. Copy setup/setadmin.php next to index.php, visit it → sets admin password, then delete it
8. Login at login.php (default: admin / Admin2026)
