# e-Dokumento Automated Tests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the manual checklist in `docs/TESTING.md` into automated tests that prove the app behaves as `README.md` describes. PHPUnit covers the PHP layer and pgTAP covers the database rules: RLS, workflow functions and constraints.

**Architecture:** The tests check the code that exists today; this plan adds no features. PHP has two layers. Unit tests load `includes/*.php` directly, without `includes/bootstrap.php`. HTTP smoke tests start `php -S … router.php` against an unreachable Supabase URL. The database is tested on a throwaway local Supabase stack running in Docker. A script resets that stack, applies `sql/01`–`05` plus a test-fixtures file, then runs pgTAP files that each roll back.

**Tech Stack:** PHP 8.3 (8.1 minimum), PHPUnit 10.5 via Composer, Supabase CLI (`npx supabase`) on Docker Desktop, pgTAP, Git Bash.

**Spec:** `README.md` (roles, security model, workflow, limits) and `docs/TESTING.md` (the behaviors to prove). All paths in this plan are relative to the app root `e-dokumento/` (the git repository root is its parent folder).

## Global Constraints

- **Do not change application code** (`api/`, `config/`, `includes/`, `pages/`, `endpoints/`, `sql/`, `assets/`) to make a test pass. If a test fails because the app disagrees with README or TESTING.md, stop. Report the test name, the expected value and the actual value to the user.
- Tests need PHP ≥ 8.1. The code uses `never` and `readonly`, so XAMPP's PHP 8.0.30 cannot even parse it. Install PHP 8.3 for Windows (windows.php.net zip, or `winget install PHP.PHP.8.3`) with `curl`, `mbstring` and `openssl` enabled, and put it ahead of `C:\xampp\php` on PATH. `php -v` must print 8.3.
- PHP test tooling lives entirely under `tests/` (`tests/composer.json`, `tests/vendor/`), so the Vercel PHP runtime never sees a root `composer.json`.
- Database tests run **only** against the local Docker stack (`npx supabase start`). Never against the live project: README step 5 notes that local dev shares the live database. No script takes a database URL.
- Exact values from the spec, used throughout: upload limit `2097152` bytes; allowed types PDF, JPG and PNG; password 8–72 characters with upper, lower and digit; seeded fees BC `50.00`, COR `30.00`, COI `0.00`, BBC `300.00`, FTJ `0.00`; BC requires captain approval and COR does not; FTJ is once per resident; reasons must be 10–500 characters.
- Database errors raised by `raise exception` have SQLSTATE `P0001`. Assert the exact message text quoted in this plan.
- Every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Wrong PHP on PATH.** XAMPP's 8.0 would produce a confusing parse error. Expected: the tests stop at once with `Tests need PHP 8.1 or newer; found 8.0.30`. Pinned in Task 1, `tests/bootstrap.php`.
2. **Test or tooling files shipped to Vercel.** Expected: `vercel.json` `excludeFiles` lists `tests/**` and `supabase/**`, and no root `composer.json` exists. Pinned in Task 1 by `DeployConfigTest`.
3. **The DB test script reaching a non-local database.** Expected: `tests/sql/run.sh` only talks to the `supabase_db_e-dokumento` container and aborts if that container is not running. Pinned in Task 5, Step 4.
4. **CRLF line endings in `run.sh` on Windows** break bash (`$'\r': command not found`). Expected: `.gitattributes` forces LF for `*.sh`. Pinned in Task 5, Step 1.
5. **Port 8099 already in use, or the server failing to start,** would make HTTP tests hang or test the wrong process. Expected: a clear failure within 5 seconds naming the port, which can be overridden with `TEST_HTTP_PORT`. Pinned in Task 4, `ServerTestCase`.

---

### Task 1: PHPUnit harness, Validator and helper tests

**Files:**
- Create: `tests/composer.json`, `tests/phpunit.xml`, `tests/bootstrap.php`
- Create: `tests/Unit/ValidatorTest.php`, `tests/Unit/HelpersTest.php`, `tests/Unit/DeployConfigTest.php`
- Modify: `.gitignore`, `vercel.json` (`excludeFiles`), `package.json` (scripts), `README.md` (new section "Running the tests", subsection "PHP tests", placed before "10. Troubleshooting")

**Interfaces:**
- Produces: `tests/bootstrap.php`. It defines `BASE_PATH` (the app root), calls `putenv()` for the test env below, calls `ob_start()` (so `setcookie()` works in CLI), and requires `includes/{env,helpers,supabase,auth,permissions,csrf,flash,validator,upload,reports}.php`. It does **not** require `includes/bootstrap.php`.
- Test env (also reused by Task 4): `SUPABASE_URL=http://127.0.0.1:9`, `SUPABASE_PUBLISHABLE_KEY=sb_publishable_test`, `SUPABASE_SECRET_KEY=sb_secret_SENTINEL_DO_NOT_LEAK`, `APP_URL=http://127.0.0.1`, `APP_SECRET=` 64 × `a`, `APP_DEBUG=false`.
- Produces commands: `composer --working-dir=tests install`, then `php tests/vendor/bin/phpunit -c tests/phpunit.xml`. npm: `"test:php": "php tests/vendor/bin/phpunit -c tests/phpunit.xml"`.

- [ ] **Step 1: Create the harness**

`tests/composer.json`: `"require-dev": {"phpunit/phpunit": "^10.5"}`, `"config": {"platform-check": false}`.
`tests/phpunit.xml`: `bootstrap="bootstrap.php"`, `cacheDirectory=".phpunit.cache"`, `failOnWarning="true"`, and two test suites: `unit` → `Unit/`, `http` → `Http/`.
`tests/bootstrap.php` first line after `declare`: `if (PHP_VERSION_ID < 80100) { fwrite(STDERR, 'Tests need PHP 8.1 or newer; found ' . PHP_VERSION . PHP_EOL); exit(1); }`.
`.gitignore`: add `tests/vendor/`, `tests/.phpunit.cache/`.
`vercel.json`: change `excludeFiles` to `"{sql/**,docs/**,.vscode/**,node_modules/**,tests/**,supabase/**}"`.

- [ ] **Step 2: Write `ValidatorTest`** (one test method per line; `$v = new Validator([...])`, then assert the return value and `$v->errors()`)

| Call | Input | Return | Error |
|---|---|---|---|
| `text('name','Name')` | `''` | `null` | `Name is required.` |
| `text('name','Name')` | `'<b>x'` | — | `Name cannot contain < or >.` |
| `text('name','Name', true, 0, 5)` | `'abcdef'` | — | `Name must be 5 characters or fewer.` |
| `name('first','First name')` | `"Ma. O'Neil-Cruz"`, `'Ñoño'` | the input | none |
| `name('first','First name')` | `'J0hn'` | — | `First name can only contain letters, spaces, periods, apostrophes and hyphens.` |
| `email('email')` | `' A@B.CO '` | `'a@b.co'` | none |
| `email('email')` | `'nope'` | — | `Enter a valid email address.` |
| `phone('m')` | `'0917-123 4567'` / `'+639171234567'` | `'09171234567'` / same | none |
| `phone('m')` | `'08171234567'` | — | `Mobile number must look like 09171234567 or +639171234567.` |
| `integer('c','Copies', true, 1, 3)` | `'12a'` / `'5'` | `null` / `5` | `Copies must be a whole number.` / `Copies must be between 1 and 3.` |
| `decimal('a','Amount')` | `'1,250.5'` | `'1250.50'` | none |
| `decimal('a','Amount')` | `'50.123'` | `null` | `Amount must be an amount like 50 or 50.00.` |
| `date('b','Birth date')` | `'2024-02-30'` | `null` | `Birth date must be a valid date.` |
| `date('b','Birth date', true, '2026-10-06')` | `'2999-01-01'` | — | `Birth date cannot be after Oct 6, 2026.` |
| `in('s','sex',['male','female'])` | `''` / `'x'` | `null` | `Choose sex.` / `Choose a valid sex.` |
| `uuid('id','Request')` | upper-case UUID | lower-cased | none |
| `password('p','p2')` | `'short'` | — | `Password must be 8 to 72 characters.` |
| `password('p','p2')` | `'alllowercase1'` | — | `Password needs an uppercase letter, a lowercase letter and a number.` |
| `password('p','p2')` | `'Abcdefg1'` vs `'Abcdefg2'` | — | key `p2`: `The passwords do not match.` |
| `error('f','one'); error('f','two')` | — | — | `f` stays `one` |

- [ ] **Step 3: Write `HelpersTest`**

- `safe_next`: `'/dashboard'` → same; `'//evil.com'`, `'https://evil.com'`, `'/\\evil'`, `''`, `null` → `null`.
- `is_uuid('not-a-uuid')` false; `is_date('2024-02-29')` true; `'2023-02-29'` and `'2024-2-1'` false.
- `search_term('ana,(or)*')` → `'ana or'`; `search_term('Peña')` → `'Peña'`.
- `url('requests/view', ['id' => 5, 'x' => '', 'y' => null])` → `'/requests/view?id=5'`.
- `e('<a href="x">')` → `'&lt;a href=&quot;x&quot;&gt;'`; `money(1234.5)` → `'₱1,234.50'`.
- `ordinal`: 1→`1st`, 2→`2nd`, 3→`3rd`, 4→`4th`, 11→`11th`, 12→`12th`, 13→`13th`, 21→`21st`, 112→`112th`.
- `resident_name(['first_name'=>'Juan','middle_name'=>'Santos','last_name'=>'Dela Cruz','suffix'=>'Jr.'])` → `'Juan S. Dela Cruz Jr.'`; with `true` → `'Dela Cruz, Juan S. Jr.'`.
- `one([['a'=>1]])` and `one(['a'=>1])` → `['a'=>1]`; `one([])` → `null`.
- `report_cell(null,'money',true)` → `''`; `report_cell('1234.5','money',true)` → `'1234.50'`; `report_cell('1234.5','money')` → `'₱1,234.50'`.
- `db_error(new SupabaseException($msg, $status, $code))`:
  - `('dup residents_identity_uniq', 409, '23505')` → `A resident with the same name and birth date is already registered.`
  - `('x', 409, '23503')` → `This record is linked to other records. Deactivate it instead of deleting it.`
  - `('Custom rule.', 400, 'P0001')` → `Custom rule.`
  - `('x', 401, null)` → `Your session has ended. Sign in again.`
  - `('x', 403, '42501')` → `You do not have permission to perform this action.`

- [ ] **Step 4: Write `DeployConfigTest`**

`vercel.json` decodes to JSON whose `functions['api/index.php'].excludeFiles` contains both `tests/**` and `supabase/**`. `BASE_PATH . '/composer.json'` does not exist.

- [ ] **Step 5: Run the tests**

Run: `composer --working-dir=tests install && npm run test:php -- --testsuite unit`
Expected: `OK`, with no failures, warnings or risky tests. A failure here is a spec disagreement: follow Global Constraints.

- [ ] **Step 6: Prove the harness can fail**

Temporarily change the expected `money(1234.5)` value to `'₱1,234.5'` and run again. Expected: 1 failure. Revert.

- [ ] **Step 7: Document and commit**

README "Running the tests → PHP tests": the PHP ≥ 8.1 requirement, the two commands from Interfaces, and the fact that `.env` is not needed.

```bash
git add tests .gitignore vercel.json package.json README.md
git commit -m "test: add PHPUnit harness with validator and helper tests" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Security primitive tests: CSRF, flash, env, upload

**Files:**
- Create: `tests/Unit/CsrfTest.php`, `tests/Unit/FlashTest.php`, `tests/Unit/EnvTest.php`, `tests/Unit/UploadTest.php`
- Modify: `docs/TESTING.md`

**Interfaces:**
- Consumes: `tests/bootstrap.php` (Task 1). Each test resets `$_COOKIE` in `setUp()`.

- [ ] **Step 1: Write the tests**

`CsrfTest`
- With `$_COOKIE['edk_csrf'] = str_repeat('a', 64)`: `Csrf::token()` equals `hash_hmac('sha256', str_repeat('a', 64), str_repeat('a', 64))`, and `Csrf::field()` contains `name="_csrf"` and that token.
- With `$_COOKIE['edk_csrf'] = 'xyz'`: after `Csrf::token()`, `$_COOKIE['edk_csrf']` matches `/^[a-f0-9]{64}$/` and is not `'xyz'`.

`FlashTest`
- `Flash::add('success', 'Saved.')`, then `Flash::pull()` returns `[['type'=>'success','message'=>'Saved.']]`, and a second `pull()` returns `[]`.
- A 400-character message comes back 300 characters long.
- Five `add` calls: `pull()` returns 4 items, the last 4.
- A tampered cookie (valid base64 JSON with signature `str_repeat('0', 64)`) makes `pull()` return `[]`.

`EnvTest` (write a temp `.env` with unique keys)
- `T1=plain`, `T2="a b"`, `T3='c'`, `# T4=x`, `NOEQUALS`. After `Env::load($path)`: `get('T1')` is `'plain'`, `get('T2')` is `'a b'`, `get('T3')` is `'c'`, `get('T4')` is `null`.
- `putenv('T1=fromenv')` makes `get('T1')` return `'fromenv'`, because the real env wins. Clean up with `putenv('T1')`.
- `Env::require('NOPE_X')` throws `RuntimeException` whose message contains `Missing environment variable NOPE_X`.

`UploadTest`
- `Upload::check(null, 'Valid ID', true)` returns `'Valid ID is required.'`, and with `false` returns `null`.
- `check(['error' => UPLOAD_ERR_INI_SIZE], 'Valid ID', true)` returns `'Valid ID must be 2 MB or smaller.'`. This is the "3 MB file" case.
- `check(['error' => UPLOAD_ERR_OK, 'tmp_name' => <real temp file>, 'size' => 10, 'name' => 'a.pdf'], 'Valid ID', true)` returns `'Valid ID could not be uploaded. Try again.'` (it was not a real upload).
- Private `sniff`, called via `ReflectionMethod`, on temp files with these contents:
  - `"%PDF-1.7\n"` → `application/pdf`
  - `"\xFF\xD8\xFF\xE0"` → `image/jpeg`
  - `"\x89PNG\r\n\x1A\n"` → `image/png`
  - `"PK\x03\x04"` (a .docx renamed to .pdf) → `null`
  - empty file → `null`
- `Upload::MAX_BYTES === 2097152`.

- [ ] **Step 2: Run the tests**

Run: `npm run test:php -- --testsuite unit`
Expected: `OK`.

- [ ] **Step 3: Mark the checklist and commit**

In `docs/TESTING.md`, append `` — auto: `tests/Unit/UploadTest.php` `` to the line "A missing required file, a 3 MB file, or a .docx renamed to .pdf is refused".

```bash
git add tests/Unit docs/TESTING.md
git commit -m "test: cover CSRF tokens, signed flash, env loading and upload sniffing" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Route and navigation access tests

**Files:**
- Create: `tests/Unit/RoutesTest.php`
- Modify: `docs/TESTING.md`

**Interfaces:**
- Consumes: `config/routes.php`, which returns `path => [file, access]`, where access is `'public' | 'guest' | 'auth' | list<string>`. Also consumes `nav_for_role(?string): array` from `includes/permissions.php`, whose entries are `[$id, $href, $icon, $label]` nested as `[[groupTitle, [entries…]], …]`.

- [ ] **Step 1: Write the tests**

- `test_every_route_file_exists`: for each route, `is_file(BASE_PATH . '/' . $file)` is true.
- `test_role_restricted_routes`: access equals exactly:
  - `/users`, `/settings`, `/officials`, `/document-types`, `/requirements`, `/reference` → `['admin']`
  - `/audit` → `['admin','captain']`
  - `/verifications`, `/residents/form` → `['secretary']`
  - `/requests/new` → `['resident','secretary']`
  - `/residents` → `['admin','captain','secretary']`
  - `/payments`, `/reports` → `['admin','captain','secretary','treasurer']`
  - `/login`, `/register`, `/forgot-password` → `'guest'`
  - `/verify` → `'public'`
- `test_secretary_cannot_reach_users` and `test_resident_cannot_reach_residents`: the role is not in that route's access list.
- `test_nav_links_are_reachable_by_their_role` (data provider: `admin`, `captain`, `secretary`, `treasurer`, `resident`): every nav `href` with its query string stripped is a route key, and its access is `'auth'`, `'public'`, or a list containing the role.

- [ ] **Step 2: Run the tests**

Run: `npm run test:php -- --filter RoutesTest`
Expected: `OK`.

- [ ] **Step 3: Mark the checklist and commit**

Append `` — auto (route map): `tests/Unit/RoutesTest.php` `` to "Opening `/users` as a Secretary shows the Access Denied page".

```bash
git add tests/Unit/RoutesTest.php docs/TESTING.md
git commit -m "test: pin route access map and per-role navigation" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: HTTP smoke tests through the front controller

**Files:**
- Create: `tests/Http/ServerTestCase.php`, `tests/Http/FrontControllerTest.php`
- Modify: `docs/TESTING.md`

**Interfaces:**
- Produces: `abstract class ServerTestCase extends TestCase`.
  - `setUpBeforeClass()` starts `PHP_BINARY -S 127.0.0.1:$port router.php` via `proc_open`, with cwd `BASE_PATH` and port `getenv('TEST_HTTP_PORT') ?: 8099`. The env is `array_merge(getenv(), <Task 1 test env>)`. Merging matters because on Windows a child process without `SystemRoot` cannot open sockets.
  - It polls `fsockopen` for up to 5 s. Otherwise it fails with `Test server did not start on 127.0.0.1:<port>`.
  - `tearDownAfterClass()` terminates the process.
  - `protected static function http(string $method, string $path, array $form = [], array $cookies = []): array{status:int, headers:array<string,string>, body:string}` makes no redirect-following and sends `ignore_errors`. Header names are lower-cased.

- [ ] **Step 1: Write the tests**

- `GET /no-such-page` → 404, and the body contains `This page doesn't exist`.
- `GET /residents` with no cookies → 303, and `location` is `/login?next=%2Fresidents`.
- `GET /login` → 200, and `GET /login.php` → 200. `/login` also has `cache-control: no-store, private`.
- Security headers on `GET /login`: `x-frame-options: DENY`, `x-content-type-options: nosniff`, and `content-security-policy` containing `frame-ancestors 'none'`.
- `POST /login` with `email`/`password` and no `_csrf` → 419, and the body contains `This form expired. Reload the page and try again.`
- `POST /login` with cookie `edk_csrf=<64 × b>` and `_csrf = hash_hmac('sha256', 64×b, 64×a)` returns a status other than 419. The body contains `Sign-in is unavailable right now. Try again in a moment.` because the Supabase URL is unreachable. This is the positive control.
- For each of `/login`, `/register`, `/forgot-password`, `/verify`, the body does not contain `SENTINEL_DO_NOT_LEAK`.

- [ ] **Step 2: Run the tests**

Run: `npm run test:php -- --testsuite http`
Expected: `OK` in under 30 s. If `/login` returns 500, the page cannot render while Supabase is unreachable. That is a spec disagreement: report it under Global Constraints.

- [ ] **Step 3: Mark the checklist and commit**

Append `` — auto: `tests/Http/FrontControllerTest.php` `` to:
- "Opening `/residents` while signed out redirects to Sign in"
- "Submitting a form copied to another site fails with "This form expired" (CSRF)"
- "No secret key appears in page source or browser network responses"

```bash
git add tests/Http docs/TESTING.md
git commit -m "test: add HTTP smoke tests for routing, CSRF, headers and secret leakage" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Database test harness and RLS tests

**Files:**
- Create: `supabase/config.toml` (via `npx --yes supabase init`, then set `project_id = "e-dokumento"`)
- Create: `tests/sql/run.sh`, `tests/sql/fixtures.sql`, `supabase/tests/01_rls.test.sql`, `.gitattributes`
- Modify: `package.json` (`"test:db": "bash tests/sql/run.sh"`, `"test": "npm run test:php && npm run test:db"`), `.gitignore` (`supabase/.branches/`, `supabase/.temp/`), `README.md` ("Running the tests → Database tests"), `docs/TESTING.md`

**Interfaces:**
- Produces, in `tests/sql/fixtures.sql` (schema `tests`). Data helpers are called only while running as `postgres`:
  - `tests.create_staff(p_email text, p_role text) returns uuid` inserts into `auth.users` and returns the profile id. Columns: `id, instance_id='00000000-0000-0000-0000-000000000000', aud='authenticated', role='authenticated', email, encrypted_password='', email_confirmed_at=now(), raw_app_meta_data={"app_role": p_role}, raw_user_meta_data={"full_name": p_email}, created_at, updated_at`. The `handle_new_user` trigger creates the profile.
  - `tests.create_resident(p_email text, p_first text, p_last text, p_verified boolean default true) returns uuid` returns the profile id. User metadata is the names plus `purok_id` (Purok 1), `birth_date '1990-01-01'`, `sex 'female'`, `civil_status 'single'`, `street_address '123 Rizal St'` and `resident_since '2015-01-01'`. When `p_verified`, it sets `verification_status = 'verified'`. `residents.email` must match `^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$`, so every test address uses the form `<name>@example.test`. A residents shorthand like `ana` in later tasks means `ana@example.test`.
  - `tests.resident_of(p_profile uuid) returns uuid` returns the resident id.
  - `tests.type_id(p_code text) returns smallint` and `tests.purpose_id() returns smallint` (`'Employment'`).
  - `tests.id_attachments(p_resident uuid) returns jsonb`: `[{"path":"<resident>/id.pdf","name":"id.pdf","mime":"application/pdf","size":1000,"requirement_id":<id of 'Valid government-issued ID'>}]`.
  - `tests.request_in(p_resident uuid, p_code text, p_status text) returns uuid` inserts directly into `document_requests` with `channel 'walk_in'`, the type's fee, `requested_by` set to the resident's profile and `tests.purpose_id()`. For `released` it also sets `released_at = now()` and `released_to = 'Test Claimant'`, and for `rejected` it sets `rejection_reason = 'Rejected in test fixture'`.
  - `tests.add_captain() returns smallint` adds an active `punong_barangay` official with the term `2025-07-01`–`2028-06-30`.
- Role switchers, granted to `anon` and `authenticated`:
  - `tests.act_as(p_user uuid)` sets `request.jwt.claims` to `{"sub": p_user, "role": "authenticated"}` (transaction-local) and runs `set local role authenticated`.
  - `tests.act_as_anon()` does the same with the role `anon` and empty claims.
  - To return to `postgres`, test files use `reset role; select set_config('request.jwt.claims', '', true);`.
- Every `supabase/tests/*.test.sql` file has the form `begin; select plan(N); … select * from finish(); rollback;`.

- [ ] **Step 1: Add prerequisites and LF endings**

Install Docker Desktop and start it. `.gitattributes`: `*.sh text eol=lf`. Run `npx --yes supabase init`, set `project_id = "e-dokumento"` in `supabase/config.toml`, then run `npx --yes supabase start`.
Expected: `docker ps` lists `supabase_db_e-dokumento`.

- [ ] **Step 2: Write `tests/sql/run.sh`**

It runs with `set -euo pipefail`, from the app root (`cd "$(dirname "$0")/../.."`). Steps:
1. `npx --yes supabase start`.
2. Abort with `Local Supabase container supabase_db_e-dokumento is not running` unless `docker ps --format '{{.Names}}'` contains that name.
3. `npx --yes supabase db reset --local`.
4. For `sql/01_schema.sql sql/02_functions.sql sql/03_policies.sql sql/04_storage.sql sql/05_seed.sql tests/sql/fixtures.sql`, run `docker exec -i supabase_db_e-dokumento psql -U postgres -d postgres -v ON_ERROR_STOP=1 -q < "$f"`.
5. `npx --yes supabase test db`.

`fixtures.sql` starts with `create extension if not exists pgtap with schema extensions;`.

- [ ] **Step 3: Write `supabase/tests/01_rls.test.sql`**

Setup as `postgres`: residents `ana@example.test` and `ben@example.test`, plus staff `treasurer@example.test` (treasurer) and `sec@example.test` (secretary), and one `request_in(ana, 'COR', 'pending')`.

- 19 rows: `select count(*) from pg_tables where schemaname = 'public' and rowsecurity`.
- `request_list`, `payment_list` and `issued_document_list` have `security_invoker=on` in `pg_class.reloptions`.
- `select count(*) from storage.buckets where id in ('verification-ids','request-files') and not public` is 2.
- As `ana`: `select count(*) from residents` is 1, and that row's `profile_id` is ana's. `document_requests` has 1 row.
- As `ben`: `document_requests` has 0 rows, and `audit_logs` has 0 rows.
- As `treasurer`: `audit_logs` has 0 rows.
- As `sec`: `residents` has 2 rows.
- As anon: `select count(*) from residents` throws `42501`. `03_policies.sql` revokes `SELECT` from `anon`, so this is permission denied, not 0 rows.

- [ ] **Step 4: Run the tests**

Run: `npm run test:db`
Expected: the five SQL files apply with no `ERROR`, and the output ends with `All tests successful.`
Then run `docker stop supabase_db_e-dokumento && npm run test:db`. Expected: `npx supabase start` brings the stack back, or the script exits non-zero with the "not running" message. In no case does it touch another database. Restart the stack afterwards if needed.

- [ ] **Step 5: Prove a policy test can fail**

Temporarily change the expected resident count for `ana` to 2 and run again. Expected: `not ok`, and the run fails. Revert.

- [ ] **Step 6: Document and commit**

README "Running the tests → Database tests": Docker Desktop is required, `npm run test:db` is the command, it runs only against the local stack, and the live project is never used. In TESTING.md, append `` — auto: `supabase/tests/01_rls.test.sql` `` to "With a resident's access token, `GET /rest/v1/residents` returns only their own row (RLS)".

```bash
git add supabase tests/sql .gitattributes .gitignore package.json README.md docs/TESTING.md
git commit -m "test: add local Supabase pgTAP harness and RLS tests" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Request creation and integrity tests

**Files:**
- Create: `supabase/tests/02_submit_request.test.sql`, `supabase/tests/03_integrity.test.sql`
- Modify: `docs/TESTING.md`

**Interfaces:**
- Consumes: the `tests.*` fixtures (Task 5) and `public.submit_request(p_resident_id uuid, p_document_type_id integer, p_purpose_id integer, p_purpose_details text, p_copies integer, p_extra jsonb, p_attachments jsonb, p_fee_waived boolean, p_waiver_reason text) returns jsonb {id, control_no}`.

- [ ] **Step 1: Write `02_submit_request.test.sql`**

All calls run as the named user via `tests.act_as`. `BC` is the shorthand for `tests.type_id('BC')`. Messages are asserted with `throws_ok(…, 'P0001', '<message>')`.

- Verified `ana` submits BC with `tests.id_attachments(ana_res)`. The result's `control_no` matches `^REQ-[0-9]{4}-[0-9]{6}$`. The request has `status 'pending'`, `channel 'online'` and `fee_amount 50.00`. There is 1 history row with `to_status 'pending'`.
- Ana submits BC again → `There is already an open request for Barangay Clearance. Track it in Requests.`
- Unverified `ben` (`p_verified => false`) submits COR → `Your residency must be verified before you can request documents online.`
- Verified `cy` submits BC with `'[]'` attachments → `Upload the required files: Valid government-issued ID.`
- Ana submits for `ben_res` → `You can only request documents for yourself.`
- Ana submits COR with `p_copies 4` → `You can request 1 to 3 copies of this document.`
- First Time Jobseeker: as `postgres`, `tests.request_in(dee_res, 'FTJ', 'released')`, then as `dee` submit FTJ → `First Time Jobseeker Certification can only be issued once per resident.`
- The secretary files a walk-in COR for unverified `ben_res` with no attachments. It succeeds with `channel 'walk_in'`, and ben's `verification_status` becomes `'verified'`.

- [ ] **Step 2: Write `03_integrity.test.sql`**

SQLSTATE assertions use `throws_ok(…, '<code>')`, run as `postgres` unless a role is stated.

- As the secretary, inserting into `residents` a second row with ana's first name, last name and `birth_date '1990-01-01'` (and the other required columns) → `23505`.
- Insert into `document_requests` with `resident_id = gen_random_uuid()` → `23503`.
- Deleting the requirement `Valid government-issued ID` → `23503`.
- A request in `for_payment` gets one `posted` payment inserted directly. A second `posted` payment for the same request → `23505`. Deleting the request → `23503`.
- A second `issued_documents` row for a request that already has one → `23505` (use `tests.add_captain()` for `signatory_id`).
- As ana, `update profiles set role_id = 1 where id = ana` → `You cannot change role, status or email from your profile.`
- After setting `profiles.status = 'inactive'` for the secretary, as that secretary `transition_request(<pending id>, 'under_review')` → `Sign in to continue.`

- [ ] **Step 3: Run the tests**

Run: `npm run test:db`
Expected: `All tests successful.`

- [ ] **Step 4: Mark the checklist and commit**

Append `` — auto: `supabase/tests/02_submit_request.test.sql` `` to the five Create items other than the file-upload item. Append `` — auto: `supabase/tests/03_integrity.test.sql` `` to all three Relationships items and to "A requirement linked to a document type cannot be deleted".

```bash
git add supabase/tests docs/TESTING.md
git commit -m "test: cover request submission rules and relational integrity" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Lifecycle, payments, issuance, verification and audit tests

**Files:**
- Create: `supabase/tests/04_lifecycle.test.sql`, `supabase/tests/05_payments.test.sql`, `supabase/tests/06_documents.test.sql`
- Modify: `docs/TESTING.md`

**Interfaces:**
- Consumes: the `tests.*` fixtures, and these functions:
  - `review_attachment(uuid, text 'accepted'|'rejected', text)`
  - `transition_request(uuid, text, text remarks, text released_to) returns jsonb`
  - `record_payment(uuid, text or_number, numeric, text method, text reference_no) returns uuid`
  - `void_payment(uuid, text)`
  - `issue_document(uuid, text) returns jsonb`
  - `revoke_document(uuid, text)`
  - `verify_document(text) returns jsonb {found, status, expired, …}`
  - `report_residents_by_purok()`

- [ ] **Step 1: Write `04_lifecycle.test.sql`**

This is the README step 9 walk-through, from a BC that ana submitted online. `tests.add_captain()` runs first, and each step runs as the role named.

| Step | Assertion |
|---|---|
| Secretary `pending → under_review` | status `under_review` |
| Secretary `→ for_payment` before accepting the file | `Accept every required file first (1 still pending or rejected).` |
| Secretary `review_attachment(att, 'accepted')`, then `→ for_payment` | status `for_payment` |
| Treasurer `record_payment(req, 'OR-1001', 50, 'cash')` | status `processing` |
| Secretary `issue_document(req)` | `Send this request for the captain's approval first.` |
| Secretary `→ for_approval` | status `for_approval` |
| Captain `issue_document(req)` | status `ready_for_release`; 1 `issued_documents` row; `verification_code ~ '^[0-9A-F]{10}$'` |
| Ana `→ released` | `That status change is not allowed for your role at this stage.` |
| Secretary `→ ready_for_release` on any request | `Use Issue document to complete this step.` |
| Secretary `→ released` with `released_to 'A'` | `Enter the name of the person who claimed the document.` |
| Secretary `→ released` with `'Ana Reyes'` | status `released`; `released_to = 'Ana Reyes'` |
| Whole run | `request_status_history` has 7 rows for the request; `audit_logs` has exactly 4 rows with `action = 'status_change'` (the four `transition_request` calls that succeeded), 1 with `action = 'approve'`, and ≥ 1 with `action = 'payment_post'` for it |

Plus COR (no captain approval): it reaches `processing` via `request_in`, and the secretary `→ for_approval` gets `This document does not need the captain's approval. Issue it directly.`
Plus rejection, on `tests.request_in(ben_res, 'COR', 'under_review')`: a secretary rejection with the reason `'short'` gets `Give a reason of 10 to 500 characters.` A valid reason keeps the row, with `status 'rejected'` and `rejection_reason` stored.
Plus cancellation, on `tests.request_in(ana_res, 'COR', 'pending')`: ana cancels her own request. The row remains, with `status 'cancelled'`.

- [ ] **Step 2: Write `05_payments.test.sql`**

All on a BC `request_in(…, 'for_payment')` with fee 50.00, as the treasurer unless stated.

- Amount `40` → `Amount must be exactly PHP 50.00.`
- `'gcash'` with no reference → `Enter the reference number for this payment.`
- As the secretary → `Only the Barangay Treasurer records payments.`
- Paying a `pending` request → `This request is not waiting for payment.`
- `'or-1001'` succeeds and stores `or_number 'OR-1001'`. On a second `for_payment` request, `'OR-1001'` → `OR number OR-1001 has already been used.`
- `void_payment(pay, 'short')` → `Give a reason of 10 to 500 characters.`
- `void_payment(pay, 'Wrong amount encoded')` keeps the row, with `status 'voided'`, `void_reason` set and `voided_at` not null. `audit_logs` has a `payment_void` row.

- [ ] **Step 3: Write `06_documents.test.sql`**

- With no active captain, the secretary's `issue_document` on a COR in `processing` → `Add the active Punong Barangay under Officials before issuing documents.`
- After `tests.add_captain()`, the secretary issues it. `verify_document(code)` → `found = true`, `status = 'valid'`, `expired = false`. Passing the code in lower case gives the same result.
- As `postgres`, set `valid_until = current_date - 1`. `verify_document(code)` → `expired = true`.
- `revoke_document(id, 'short')` → `Give a reason of 10 to 500 characters.` A valid reason gives `status 'revoked'` and keeps the row. `verify_document(code)` → `status = 'revoked'`.
- `verify_document('ZZZZZZZZZZ')` and `verify_document('0000000000')` → `found = false`.
- `verify_document` is callable as anon (via `tests.act_as_anon()`).
- Report totals: for `Purok 1`, `report_residents_by_purok()` `total` equals `select count(*) from residents where purok_id = <Purok 1> and status = 'active'`, compared as the secretary.

- [ ] **Step 4: Run the tests**

Run: `npm run test:db`
Expected: `All tests successful.`

- [ ] **Step 5: Run everything and mark the checklist**

Run: `npm test`
Expected: PHPUnit `OK`, then `All tests successful.`
In TESTING.md, append `` — auto: `supabase/tests/0{4,5,6}_*.test.sql` `` (naming the specific file) to:
- every Update item except "Editing a resident changes the row"
- "Cancelled, voided and revoked rows remain…"
- the three `/verify` items under Read
- "Report totals match…"
- "Sign-ins, edits, status changes, payments and voids appear in the Audit log" (append "(status changes, payments, voids)")

Leave everything else manual: login, email, session refresh, CSV-in-Excel, UI search and pagination, confirmation dialogs, and deployment.

- [ ] **Step 6: Commit**

```bash
git add supabase/tests docs/TESTING.md
git commit -m "test: cover request lifecycle, payments, issuance, verification and audit" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
