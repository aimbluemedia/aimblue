# AimBlue

Website project for AimBlue Media, developed with [Claude Code](https://claude.com/claude-code) and deployed to Hostinger.

## Project structure

```
aimblue/
├── public/                  # The website itself — everything in here gets deployed
│   ├── index.html
│   └── assets/
│       ├── css/style.css
│       └── js/main.js
├── .github/workflows/
│   └── deploy.yml           # Auto-deploys public/ to Hostinger on every push to main
└── docs/
    └── SETUP-HOSTINGER.md   # One-time setup: connecting GitHub to Hostinger
```

## How it works

1. Edit files in `public/` (or ask Claude Code to).
2. Commit and push to the `main` branch on GitHub.
3. GitHub Actions automatically uploads the site to Hostinger via FTP.

Before the first deployment works, you need to complete the one-time setup in
[docs/SETUP-HOSTINGER.md](docs/SETUP-HOSTINGER.md) — it takes about 5 minutes.

## Working locally

The site is plain HTML/CSS/JS, so there's no build step. To preview it locally:

```bash
cd public && python3 -m http.server 8000
```

Then open http://localhost:8000 in your browser.
