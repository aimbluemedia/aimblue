# Content Board

Multi-company social media content tracking dashboard for AimBlue Media.

**Stack:** PHP 8.x · MySQL · Vanilla JS · Bootstrap 5 · Bootstrap Icons
**Hosting:** Hostinger shared hosting, auto-deployed from GitHub

## Features
- Multi-company dashboard with post calendar and stats
- Content Managers and Sales People management
- Platform cards (Facebook, Instagram, TikTok, YouTube, Reddit, Pinterest, Blog)
- Social Media Logins vault (AES-256 encrypted passwords)
- Per-company posting channels with login details
- Role-based access: Admin / Content Manager / Sales Person
- Dual roles (user can be both CM and SP)

## Repo layout

```
public/       The app — auto-deployed to Hostinger public_html on every push to main
setup/        One-time admin tools — upload manually, DELETE from server after use
database/     SQL schema + migrations — run in phpMyAdmin, never uploaded to webroot
legacy/       Original Claude artifact prototype (reference only)
docs/         Setup guides
```

## How deployment works

Push to `main` → GitHub Actions uploads `public/` to Hostinger via FTPS.
One-time connection setup: [docs/SETUP-HOSTINGER.md](docs/SETUP-HOSTINGER.md).

The `setup/` tools and `database/` SQL are deliberately **excluded** from
auto-deploy — `setadmin.php` and `reset-password.php` are unauthenticated and
must only ever live on the server for the minutes you're actually using them.

## First-time server setup

1. Connect GitHub → Hostinger (see docs/SETUP-HOSTINGER.md) and run a deploy
2. Visit `config.php` on your domain → enter DB credentials
3. In phpMyAdmin, import `database/content-board-mysql.sql`
4. Run all `database/migration-*.sql` files (in the order listed in CLAUDE.md)
5. Run `database/fix-fk-constraints.sql`
6. Upload `setup/setadmin.php` via hPanel File Manager, visit it to set the
   admin password, **then delete it from the server**
7. Login at `login.php`

## Key files

| File | Purpose |
|------|---------|
| `public/index.php` | Main dashboard (single-page app) |
| `public/api.php` | REST JSON API |
| `public/auth.php` | Session/auth helpers |
| `public/config.php` | DB configuration wizard |
| `public/login.php` | Login page |
| `db.config.php` | DB credentials — generated on the server, gitignored |

Full project context for Claude Code lives in [CLAUDE.md](CLAUDE.md).
