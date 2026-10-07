# Visual checks

Opens every page as each role (guest, resident, secretary, treasurer, captain, admin), in the dark
and light theme, at desktop and phone width. It saves a screenshot of each to `tools/visual/out/`
and fails if a page returns the wrong HTTP status, throws a console error, or scrolls sideways.

It runs against a **fake Supabase** (`fake_supabase.py`) with made-up data, so it needs no Supabase
project, no `.env` and touches no real data. Because of that, it checks how pages look and lay out.
It does not check business rules, permissions or database behaviour.

## Run it

Needs PHP 8.1 or newer (XAMPP's PHP 8.0 cannot parse the app), Python 3 and Google Chrome.

```bash
cd tools/visual
npm install
PHP_BIN="/path/to/php8.3/php" npm run check:quick   # dark theme only, about a minute
PHP_BIN="/path/to/php8.3/php" npm run check         # both themes
```

On Windows PowerShell: `$env:PHP_BIN = "C:\php83\php.exe"; npm run check:quick`.

Open the PNGs in `out/` to look at them. Other settings: `PYTHON` (default `python`), `APP_PORT`
(8124), `FAKE_PORT` (8125) and `PW_CHANNEL` (default `chrome`; use `msedge` for Edge).

This folder is not deployed: `vercel.json` excludes `tools/**`.
