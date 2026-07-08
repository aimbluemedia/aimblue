# Content Board

Multi-company social media content tracking dashboard for AimBlue Media.

**Stack:** PHP 8.x · MySQL · Vanilla JS · Bootstrap 5 · Bootstrap Icons
**Hosting:** Hostinger shared hosting, deployed from GitHub

## Repo layout

The app files live at the **repo root** on purpose: Hostinger's hPanel Git
deployment clones the whole repository straight into `public_html`, so the
site works immediately after every deploy — no moving files.

```
index.php, api.php, auth.php, config.php, login.php   ← the app
.htaccess     Blocks web access to everything below — DO NOT delete
setup/        One-time admin tools (web-blocked; use then remove)
database/     SQL schema + migrations — run in phpMyAdmin (web-blocked)
legacy/       Original Claude artifact prototype (web-blocked)
docs/         Setup guides (web-blocked)
```

Security relies on the root `.htaccess`: it denies public access to `setup/`,
`database/`, `legacy/`, `docs/`, all `.sql` files, git internals, and
`db.config.php`. Never delete it from the server.

## Deploying

**Hostinger Git deployment (current setup):** hPanel → Advanced → GIT is
connected to this repo. Every deploy clones the repo into `public_html` and
the site just works. Add the webhook (shown in hPanel) to the GitHub repo's
Settings → Webhooks to make every push deploy automatically.

**GitHub Actions FTP (alternative):** `.github/workflows/deploy.yml` uploads
the app via FTPS on pushes to `main`, excluding all non-app folders. Needs
`FTP_SERVER` / `FTP_USERNAME` / `FTP_PASSWORD` repo secrets — see
[docs/SETUP-HOSTINGER.md](docs/SETUP-HOSTINGER.md). Use one method or the
other, not both.

## First-time server setup

1. Deploy (either method above)
2. Visit `yourdomain.com/config.php` → enter DB credentials
   (hPanel → Databases; database `u312278121_wpi`)
3. In phpMyAdmin, import `database/content-board-mysql.sql`, then run the
   `migration-*.sql` files and `fix-fk-constraints.sql`
4. Copy `setup/setadmin.php` to `public_html` via File Manager, visit it to
   set the admin password, **then delete it from the server**
5. Login at `login.php`

## Key files

| File | Purpose |
|------|---------|
| `index.php` | Main dashboard (single-page app) |
| `api.php` | REST JSON API |
| `auth.php` | Session/auth helpers |
| `config.php` | DB configuration wizard |
| `login.php` | Login page |
| `db.config.php` | DB credentials — generated on the server, gitignored |

Full project context for Claude Code lives in [CLAUDE.md](CLAUDE.md).
