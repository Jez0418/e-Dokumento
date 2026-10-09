# e-Dokumento

A Barangay Document Request and Resident Services Information Management System. Residents request barangay clearances and certificates online; the Secretary reviews and issues them, the Treasurer records payments, and the Punong Barangay approves documents that need a signature.

**Stack:** PHP 8.3 (Vercel community runtime) · HTML, CSS, JavaScript, Bootstrap 5 · Supabase PostgreSQL, Auth, Storage and Row Level Security · deployed on Vercel. No XAMPP, Apache or MySQL.

The analysis and design document (black-box analysis, roles, flowchart, use cases, ERD, data dictionary) lives in Claude Docs; `docs/ERD.mmd` and `docs/TESTING.md` are copies kept with the code.

---

## What is in the box

```
api/index.php          Single entry point (Vercel function); routes every request
config/                app.php, supabase.php (read env vars), routes.php (page → access)
includes/              Supabase client, auth sessions, permissions, CSRF, validator,
                       uploads, flash messages, audit, layout and UI components, reports
includes/layout/       header, sidebar, navbar, footer
pages/                 One folder per module (28 pages)
endpoints/             JSON endpoints for the live dashboard and notifications
assets/                app.css, page scripts, logo
sql/                   01_schema → 05_seed, run in order
docs/                  ERD and testing checklist
router.php             Local development router (php -S)
vercel.json            PHP runtime and routes
```

**Roles:** Administrator, Punong Barangay, Barangay Secretary, Barangay Treasurer, Resident.

**Database:** 19 tables plus three read-only listing views (`request_list`, `payment_list`, `issued_document_list`). Views are not counted as tables; they use `security_invoker` so RLS still applies.

**How security works:** PHP never uses a privileged key for normal work. Every call to Supabase carries the signed-in user's access token, so PostgreSQL's RLS decides what each person may read. Request status changes, payments and certificate issuance can only happen through database functions (`transition_request`, `record_payment`, `issue_document`, …) that check the caller's role and the allowed transition. The secret key is used in one place: creating and deactivating staff accounts on the Users page.

---

## 1. Create the Supabase project

1. Sign in at [supabase.com](https://supabase.com) and create a new project. Pick the Singapore region for users in the Philippines.
2. Save the database password somewhere safe. The app does not need it.
3. Open **Project Settings → API Keys** and copy the **Project URL**, the **publishable key** (`sb_publishable_…`) and a **secret key** (`sb_secret_…`). Legacy `anon` and `service_role` keys also work.

## 2. Create the database tables

Open **SQL Editor → New query** and run each file, in this order, one at a time:

| Order | File | What it does |
| --- | --- | --- |
| 1 | `sql/01_schema.sql` | 19 tables, constraints, indexes, triggers, the three listing views |
| 2 | `sql/02_functions.sql` | Role helpers, the signup trigger, column guards, audit triggers, workflow and report functions |
| 3 | `sql/03_policies.sql` | Enables RLS on every table and creates the policies |
| 4 | `sql/04_storage.sql` | Private Storage buckets for ID photos and request files, with Storage policies |
| 5 | `sql/05_seed.sql` | Starting reference data: puroks, accepted IDs, purposes, requirements, five document types, settings |
| 9 | `sql/09_email_outbox.sql` | Optional. Email outbox, queueing trigger and sending functions for status emails (see [Turn on status emails](#turn-on-status-emails)). Safe to rerun; emails stay off until an Administrator turns them on |

`05_seed.sql` contains configuration only. No residents, requests or payments are seeded; everything you see in the app was entered through it. Set the **fees to your barangay's revenue ordinance** under Document types.

For a demonstration, `sql/08_demo_data.sql` adds 24 sample residents and 43 walk-in requests spread over the last six months, in every status. It runs each request through the real workflow functions as the test accounts from `sql/07_test_accounts.sql`, so run 07 first and add the active Punong Barangay under Officials. Demo residents have emails ending in `@demo.e-dokumento.test`; the block at the bottom of the file removes them. Do not load it into a real barangay's database.

If a file fails partway, read the error, fix it, and rerun that file. Most statements fail cleanly when the object already exists. To start over, run `drop schema public cascade; create schema public;` and reapply the standard grants Supabase documents for the public schema. Only do this on a fresh project.

## 3. Configure RLS policies

`03_policies.sql` and `04_storage.sql` already did this. To confirm:

- **Database → Tables:** every table shows **RLS enabled**.
- **Advisors → Security Advisor:** no "RLS disabled" warnings for these tables.
- **Storage:** `verification-ids` and `request-files` are listed and **not public**.

Do not disable RLS to make something work. If a page says *You do not have permission*, the policy is doing its job; check the user's role.

## 4. Configure Supabase Auth

In **Authentication**:

1. **URL Configuration → Site URL:** your production URL, e.g. `https://e-dokumento.vercel.app`.
2. **URL Configuration → Redirect URLs:** add
   - `https://e-dokumento.vercel.app/login`
   - `https://e-dokumento.vercel.app/reset-password`
   - `http://localhost:8000/login` and `http://localhost:8000/reset-password` for local work
3. **Sign In / Providers → Email:** keep it enabled. Keep **Confirm email** on for production.
4. **Password requirements:** set the minimum length to 8 and require lowercase, uppercase and digits, matching the app's own check.
5. **SMTP:** Supabase's built-in email sender allows only a few emails per hour. Before real residents register, set up custom SMTP under **Authentication → Emails → SMTP Settings**.

### Create the first Administrator

Residents register themselves; staff accounts are created by an Administrator. Create the first one by hand:

1. **Authentication → Users → Add user → Create new user.** Enter your email and a strong password and tick **Auto Confirm User**.
2. In the SQL Editor run:
   ```sql
   update public.profiles set role_id = 1 where email = 'you@example.com';
   ```
3. Sign in to the app. Then:
   - **Settings:** enter the barangay name, city or municipality, province and office hours.
   - **Officials:** add the active Punong Barangay. Documents cannot be issued until you do.
   - **Users:** create the Punong Barangay, Secretary and Treasurer accounts.

## 5. Configure local environment variables

Requirements: PHP 8.1 or newer with the `curl` and `mbstring` extensions (`php -m` lists them). Check with `php -v`.

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"   # paste the result into APP_SECRET
```

Fill in `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, `SUPABASE_SECRET_KEY`, and set `APP_URL=http://localhost:8000`. Then run:

```bash
php -S localhost:8000 router.php
```

Or, in VS Code, run the task **Run local PHP server** (Terminal → Run Task). Open http://localhost:8000.

Local runs talk to the same live Supabase database. This is only for development; production runs on Vercel.

## 6. Connect the project to GitHub

```bash
git init
git add .
git commit -m "e-Dokumento initial commit"
git branch -M main
git remote add origin https://github.com/<you>/e-dokumento.git
git push -u origin main
```

`.gitignore` already keeps `.env` out of the repository. Before pushing, run `git status` and confirm `.env` is not listed.

## 7. Deploy to Vercel

1. At [vercel.com](https://vercel.com), choose **Add New → Project** and import the GitHub repository.
2. Set **Framework Preset** to **Other**. Leave the build command and output directory empty.
3. Add the environment variables from step 8, then click **Deploy**.

`vercel.json` runs `api/index.php` on the `vercel-php@0.7.4` community runtime (PHP 8.3). It serves `/assets` as static files and sends every other path to the front controller.

From the command line instead: `npm i -g vercel`, then `vercel` and `vercel --prod`.

## 8. Configure Vercel environment variables

In **Project Settings → Environment Variables**, add these for Production (and Preview if you use it):

| Name | Value | Notes |
| --- | --- | --- |
| `SUPABASE_URL` | `https://<ref>.supabase.co` | |
| `SUPABASE_PUBLISHABLE_KEY` | `sb_publishable_…` | Sent with every request; RLS protects the data |
| `SUPABASE_SECRET_KEY` | `sb_secret_…` | Server-only; used only by the Users page |
| `APP_URL` | `https://<your-app>.vercel.app` | No trailing slash; used in email links |
| `APP_SECRET` | 64 random hex characters | Signs CSRF tokens and flash messages |
| `APP_DEBUG` | `false` | |
| `MAIL_USERNAME` | `your-barangay@gmail.com` | Optional; status emails. The Gmail account that sends them |
| `MAIL_APP_PASSWORD` | 16-character Gmail app password | Optional; status emails. Not the Gmail password |
| `MAIL_FROM_NAME` | `Barangay San Isidro e-Dokumento` | Optional; the sender name residents see. Defaults to `Barangay <name> e-Dokumento` |

Redeploy after changing variables (**Deployments → ⋯ → Redeploy**).

### Turn on status emails

Residents with an email address can get an email when a request needs them to act: it is ready for payment, ready for pickup, or rejected. This is optional and off by default.

1. Run `sql/09_email_outbox.sql` in the SQL Editor. Nothing changes for users yet.
2. On the Gmail account that will send the emails, turn on 2-Step Verification, then create an app password at **myaccount.google.com → Security → App passwords**.
3. Set `MAIL_USERNAME`, `MAIL_APP_PASSWORD` and `MAIL_FROM_NAME` in Vercel (Production), then redeploy.
4. Sign in as the Administrator and open **Settings → Email notifications**. Click **Send test email** and check your inbox.
5. Turn the switch on and click **Save**.

Emails are sent right after a staff member's action. A failed send stays queued and is tried again after the next staff action; after 5 failed attempts it is marked failed and waits for **Retry** on the same card. Gmail allows about 500 emails a day.

## 9. Verify the production deployment

1. Open the Vercel URL. The sign-in page shows the barangay name from Settings, which proves Supabase is connected.
2. Register a resident account with a second email, confirm it, and sign in.
3. Upload an ID on **Profile**. Sign in as the Secretary and approve it under **Verifications**.
4. As the resident, request a Barangay Clearance. Note the control number.
5. Walk it through to release:
   - **Secretary:** start the review, accept the file, and send it for payment.
   - **Treasurer:** record the payment.
   - **Secretary:** send it for approval.
   - **Punong Barangay:** approve it.
   - **Secretary:** print it and mark it released.
6. Check `/verify` with the certificate's code, and check that the dashboards, reports and audit log reflect each step.
7. Work through `docs/TESTING.md`.

## Running the tests

### PHP tests

The PHP tests need PHP 8.1 or newer (8.3 recommended) with `curl`, `mbstring` and `openssl` enabled. XAMPP's PHP 8.0 cannot run them, so put the newer PHP ahead of `C:\xampp\php` on PATH; `php -v` should print 8.1 or later. The test tooling lives in `tests/`, so Vercel never sees it.

```bash
composer --working-dir=tests install
npm run test:php
```

`npm run test:php` runs `php tests/vendor/bin/phpunit -c tests/phpunit.xml`. Add `-- --testsuite unit` or `-- --testsuite http` to run one suite. The tests set their own environment and point Supabase at an unreachable address, so `.env` is not needed and the live project is never contacted.

### Database tests

The database tests need Docker Desktop, running. They check RLS, the workflow functions and the constraints with pgTAP on a throwaway local Supabase stack.

```bash
npm run test:db
```

`npm run test:db` runs `tests/sql/run.sh`. It starts the local stack (`npx supabase start`; the first run downloads the images), resets the local database, applies `sql/01`–`05` and `09` plus `tests/sql/fixtures.sql`, and runs `supabase/tests/*.test.sql`. Each test file rolls back. The script works only on the local container `supabase_db_e-dokumento` and stops if that container is not running. It takes no database URL, so the live project is never used. Run it from Git Bash: in PowerShell or cmd, `bash` can resolve to WSL instead.

`npm test` runs the PHP tests, then the database tests. `npx supabase stop` shuts the local stack down when you are done.

## 10. Troubleshooting

| Symptom | Likely cause and fix |
| --- | --- |
| "The app is not configured yet: Missing environment variable…" | A Vercel variable is missing or misspelled. Add it and redeploy. |
| "APP_SECRET must be at least 32 characters" | Generate one with the command in step 5. |
| 404 on every page after deploying | `vercel.json` was not committed, or the Framework Preset is not **Other**. |
| Vercel build error mentioning `vercel-php` | Check the runtime version in `vercel.json` against the [vercel-php releases](https://github.com/vercel-community/php) and the Node.js version in Project Settings. |
| "Incorrect email or password" for a user you just created in the dashboard | Tick **Auto Confirm User**, or confirm the user under Authentication → Users. |
| Registration says "We could not create your resident record" | The signup trigger rejected the data, most often a duplicate name and birth date already in the registry. Supabase logs (**Logs → Postgres**) show the exact error. |
| Confirmation or reset email never arrives | Built-in email limits; set up custom SMTP (step 4). |
| Reset link opens the page but says to request a new link | The redirect URL is not in Supabase's allow list, or the link was already used. |
| "You do not have permission to perform this action" | RLS refused it. Check the user's role on the Users page; the profile must also be active. |
| "Add the active Punong Barangay under Officials…" | No active official with the position Punong Barangay. Add one. |
| Upload fails with a 413 error | Vercel limits a request to about 4.5 MB. Keep files under 2 MB each and 4 MB in total. |
| Users page: "Creating staff accounts needs SUPABASE_SECRET_KEY" | Add the secret key to Vercel and redeploy. |
| Signed out every few minutes locally | The browser rejects `Secure` cookies on plain http only if `APP_URL` is https; use `http://localhost:8000` locally. |

---

## Optional: UI/UX Pro Max skill in VS Code

The UI was built from the design plan in the design document. To add the UI/UX Pro Max skill for future UI work, install the **Claude Code** extension (it is in `.vscode/extensions.json`), open its terminal panel, and run:

```
/plugin marketplace add nextlevelbuilder/ui-ux-pro-max-skill
/plugin install ui-ux-pro-max@ui-ux-pro-max-skill
```

## Known limitations

- Payments through GCash or Maya are recorded by reference number; the app does not collect money.
- Notifications are in-app; residents with an email address also get an email when a request needs payment, is ready for pickup, or is rejected (Gmail, about 500 a day).
- Printed certificates still need the dry seal and a wet signature; the verification code lets offices confirm them online.
- The Treasurer's database access includes resident rows (needed to show names on payments); the interface shows names only.
- One barangay per deployment.
