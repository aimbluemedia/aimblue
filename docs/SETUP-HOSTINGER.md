# Connecting GitHub to Hostinger

Two supported ways to deploy. **Use one, not both.**

The repo is laid out so the app lives at the repo root and lands directly in
`public_html`. The root `.htaccess` blocks public access to `setup/`,
`database/`, `legacy/`, `docs/`, `.sql` files, and git internals — so both
methods below are safe.

---

## Option A (current setup): Hostinger hPanel Git deployment

1. In hPanel go to your website → **Advanced → GIT**.
2. Connect the repository: `https://github.com/aimbluemedia/aimblue.git`
   and the branch to deploy. Leave the directory blank (= `public_html`).
   For a private repo, hPanel shows an SSH key — add it on GitHub under
   the repo's **Settings → Deploy keys**.
3. Click **Deploy** to ship the current state.
4. To make every GitHub push deploy automatically: copy the **webhook URL**
   hPanel shows and add it on GitHub under the repo's
   **Settings → Webhooks → Add webhook** (content type: `application/json`).

After the first deploy, finish the app setup (database credentials via
`config.php`, admin password via `setup/setadmin.php`) — see the README.

**Note:** `db.config.php` is generated on the server and is gitignored, so
deploys never overwrite your database credentials.

---

## Option B (alternative): GitHub Actions auto-deploy via FTP

`.github/workflows/deploy.yml` uploads the app via FTPS on every push to
`main` (and manually via the Actions tab → *Run workflow*). It excludes
everything that shouldn't be in a webroot. Disconnect the hPanel Git
deployment first if you switch to this.

### Step 1 — Get your FTP credentials from Hostinger

1. Log in to [hpanel.hostinger.com](https://hpanel.hostinger.com).
2. Select your website, then go to **Files → FTP Accounts**.
3. Note down the **FTP host**, **username**, and **password**
   (reset it there if unknown).

### Step 2 — Add the credentials as GitHub secrets

Repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret name    | Value              |
|----------------|--------------------|
| `FTP_SERVER`   | FTP host           |
| `FTP_USERNAME` | FTP username       |
| `FTP_PASSWORD` | FTP password       |

> ⚠️ Never commit credentials to the repo — only GitHub secrets.

### Step 3 — Check the upload folder

The workflow uploads into `public_html/` under the FTP account's root. If
your FTP account is already rooted *inside* `public_html`, change
`server-dir: ./public_html/` to `server-dir: ./` in the workflow.

---

## Troubleshooting

- **403 Forbidden on the site** — there's no `index.php` in `public_html`.
  With the current repo layout this shouldn't happen; if it does, check the
  deploy actually ran and that it deployed the branch you expected.
- **Site asks for database setup after a deploy** — `db.config.php` went
  missing (e.g. the deploy directory was emptied). Visit `config.php` and
  re-enter the credentials from hPanel → Databases.
- **hPanel Git "deploy failed"** — the target directory usually must be
  empty for the FIRST deploy. Back up `db.config.php`, empty `public_html`,
  deploy, then restore `db.config.php` (or re-run `config.php`).
- **Actions run fails with "530 Login authentication failed"** — wrong FTP
  secrets; re-check them in hPanel.
