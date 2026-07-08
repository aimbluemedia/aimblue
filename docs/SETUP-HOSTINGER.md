# Connecting GitHub to Hostinger (one-time setup)

There are two ways to get this site from GitHub onto Hostinger. **Option A is
recommended** — it's fully automatic and already wired up in this repo. Option B
uses Hostinger's built-in Git feature and needs no secrets, but has limitations.

---

## Option A (recommended): GitHub Actions auto-deploy via FTP

Every push to the `main` branch automatically uploads `public/` to your
Hostinger web space. The workflow is already in `.github/workflows/deploy.yml` —
you just need to give GitHub your Hostinger FTP credentials as **secrets**.

### Step 1 — Get your FTP credentials from Hostinger

1. Log in to [hpanel.hostinger.com](https://hpanel.hostinger.com).
2. Select your website, then go to **Files → FTP Accounts**.
3. Note down:
   - **FTP host** (looks like `ftp.yourdomain.com` or an IP like `185.x.x.x`)
   - **FTP username** (looks like `u123456789.yourdomain.com`)
   - **FTP password** — if you don't know it, click **Change FTP password** and set a new one.

### Step 2 — Add the credentials as GitHub secrets

1. Open the repo on GitHub: `github.com/aimbluemedia/aimblue`.
2. Go to **Settings → Secrets and variables → Actions → New repository secret**.
3. Create these three secrets (names must match exactly):

   | Secret name    | Value                          |
   |----------------|--------------------------------|
   | `FTP_SERVER`   | your FTP host from step 1      |
   | `FTP_USERNAME` | your FTP username from step 1  |
   | `FTP_PASSWORD` | your FTP password from step 1  |

> ⚠️ Never put these credentials in a file inside the repo — only in GitHub
> secrets. Secrets are encrypted and hidden from logs.

### Step 3 — Check the upload folder

The workflow uploads to `public_html/` inside your FTP account's root folder.
On most Hostinger plans that's correct as-is. If your FTP account is already
rooted *inside* `public_html`, edit `.github/workflows/deploy.yml` and change
`server-dir: ./public_html/` to `server-dir: ./`.

(Not sure which you have? Just run a deploy and see where the files land —
the safe default in this repo never deletes existing files.)

### Step 4 — Deploy

Push to `main` (or go to the repo's **Actions** tab → *Deploy to Hostinger* →
**Run workflow** for a manual deploy). The first run uploads everything;
later runs only upload files that changed.

---

## Option B: Hostinger's built-in Git deployment

Hostinger can pull the repo itself, no FTP secrets needed:

1. In hPanel, go to your website → **Advanced → GIT**.
2. Enter the repository URL: `https://github.com/aimbluemedia/aimblue.git`,
   branch `main`, and the install path (leave blank for `public_html`).
3. For a **private** repo, hPanel shows an SSH key to add on GitHub under
   **Settings → Deploy keys**, and a **webhook URL** to add under
   **Settings → Webhooks** so pushes deploy automatically.

**Limitation:** this deploys the *whole repo* (README, docs, etc.) into your
web root, not just `public/`. That's why Option A is recommended for this
project layout.

---

## Troubleshooting

- **Deploy workflow fails with "530 Login authentication failed"** — the
  `FTP_USERNAME`/`FTP_PASSWORD` secrets are wrong. Re-check them in hPanel.
- **Workflow succeeds but the site doesn't change** — files probably landed in
  the wrong folder; revisit Step 3.
- **Nothing runs on push** — deploys only trigger from the `main` branch.
