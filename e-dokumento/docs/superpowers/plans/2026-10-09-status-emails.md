# Status Emails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email a resident when their request becomes For payment, Ready for release or Rejected.

**Architecture:** A trigger on `document_requests.status` adds a row to `email_outbox` in the same transaction as the status change. After a staff member's `transition_request`, `record_payment` or `issue_document` call succeeds, PHP takes queued rows one at a time and sends them over Gmail SMTP using cURL. Failures stay queued until the next staff action, and after 5 attempts a row becomes `failed` until an Administrator retries it.

**Tech Stack:** PHP 8.3 with cURL (`smtps://`), Supabase PostgreSQL (plpgsql, RLS), PHPUnit 10.5, pgTAP. Gmail with an app password.

**Spec:** `docs/superpowers/specs/2026-10-09-status-emails-design.md`

## Global Constraints

- **Prerequisite:** Tasks 1 and 5 of `docs/superpowers/plans/2026-10-06-automated-tests.md` are done. That gives `tests/bootstrap.php`, `npm run test:php`, `tests/sql/run.sh`, `tests/sql/fixtures.sql` (the `tests.*` helpers) and `npm run test:db`. If they are not done, stop and say so.
- **The person supplies:** a Gmail account with 2-Step Verification on and an app password, created at myaccount.google.com → Security → App passwords. Never commit these values.
- **SQL files:** do not edit `sql/01`–`08`. All SQL goes in `sql/09_email_outbox.sql`, every statement in it can be rerun safely (`create … if not exists`, `create or replace`, `drop trigger if exists`, `on conflict do nothing`), and every function is `security definer` with `set search_path = ''`.
- **Exact values from the spec:**
  - email statuses: `for_payment`, `ready_for_release`, `rejected`
  - outbox `status`: `queued`, `sending`, `sent`, `failed`
  - 5 attempts before `failed`
  - a `sending` row is reclaimed after 10 minutes
  - `last_error` is at most 500 characters and `subject` at most 150
  - setting key `email_notifications_enabled`, default JSON `false`
  - environment variables `MAIL_USERNAME`, `MAIL_APP_PASSWORD`, `MAIL_FROM_NAME`
  - SMTP URL `smtps://smtp.gmail.com:465`, with a 10-second timeout per send
- **Sending after `void_payment` too:** besides the spec's three functions, sending also runs after `void_payment`, which returns a request to For payment.
- **Time budget:** Vercel's `maxDuration` is 30 seconds. `flush_outbox` claims **one row per call** and starts no new send once 12 seconds have passed since it began. This replaces the spec's "up to 5 per action" (see Review Focus 1).
- **Security:** PHP never uses `Supabase::admin()` for this feature. The secret key stays on the Users page only.
- **The live database:** applying `sql/09` to the live Supabase project, and turning the switch on there, are actions the person confirms before you take them.
- **Commits:** every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. The git root is the parent of the app root; paths in this plan are relative to the app root `e-dokumento/`.

## Review Focus

1. **Gmail is slow or unreachable when several emails are queued.** Expected: the staff action still returns before Vercel's 30-second limit. `flush_outbox` stops starting sends after 12 seconds (connect timeout 5 s, total 10 s). Pinned in Task 4, `test_flush_stops_starting_sends_after_budget`.
2. **An Administrator puts a line break in a document type name or barangay name, and it ends up in the subject.** Expected: no injected header. `build_message` replaces CR and LF in the subject with spaces and refuses a recipient that contains CR, LF, `<` or `>`. Pinned in Task 3, `test_subject_newlines_cannot_inject_headers` and `test_rejects_recipient_with_newline`.
3. **Sending throws while a staff action is running** (bad password, Gmail down, the claim RPC itself failing). Expected: the action's result and the success message are unchanged, and no exception escapes. Pinned in Task 4, `test_after_rpc_swallows_every_failure`.
4. **A long subject with non-ASCII text** (ñ in a document type, ₱). Expected: every header line is at most 78 characters, and the subject decodes back to the original text. Pinned in Task 3, `test_long_utf8_subject_is_folded_and_round_trips`.
5. **A resident cancels their own request through `transition_request`.** Expected: no claim RPC is made (the resident is not staff), so there's no wasted call and no error. Pinned in Task 4, `test_after_rpc_skips_non_staff_and_other_functions`.

---

### Task 1: Feasibility check (throwaway)

This answers one question: can Vercel's PHP runtime send email through `smtps://smtp.gmail.com:465` with cURL? None of the code is kept.

**Files:**
- Create (temporary, never merged): `pages/public/mail-spike.php` and a route `'/_mail-spike' => ['pages/public/mail-spike.php', 'public']` in `config/routes.php`, on branch `spike/mail`

- [ ] **Step 1: Write the probe page**

  - It prints `in_array('smtps', curl_version()['protocols'], true)` and the libcurl version.
  - If `$_GET['key']` equals `Env::get('MAIL_SPIKE_KEY')`, which is non-empty, it also sends one plain-text email from `MAIL_USERNAME` to itself with a raw cURL SMTP call (the `CURLOPT_*` set listed in Task 3), then prints `curl_errno` and `curl_error`.

- [ ] **Step 2: Deploy as a preview**

  Ask the person to set `MAIL_USERNAME`, `MAIL_APP_PASSWORD` and a random `MAIL_SPIKE_KEY` in Vercel for the **Preview** environment only. Then push `spike/mail`. Vercel builds a preview and GitHub creates the Preview environment again.
  Run: open `https://<preview-url>/_mail-spike?key=<MAIL_SPIKE_KEY>`.
  Expected: `smtps: true`, `curl_errno 0`, and the email arrives in the Gmail inbox.

- [ ] **Step 3: Decide and clean up**

  - If the check failed, **stop** and report the exact output to the person; the mailer needs a different design.
  - If it passed, run `git push origin --delete spike/mail`, delete the local branch, and remove `MAIL_SPIKE_KEY` from Vercel.
  - Ask the person whether to delete the Preview GitHub environment again.

---

### Task 2: Outbox table and queueing trigger

**Files:**
- Create: `sql/09_email_outbox.sql`, `supabase/tests/07_email_queue.test.sql`
- Modify: `tests/sql/run.sh` (add `sql/09_email_outbox.sql` after `sql/05_seed.sql` in the list of files it applies)

**Interfaces:**
- Consumes: `tests.create_resident`, `tests.create_staff`, `tests.resident_of`, `tests.request_in`, `tests.act_as` (fixtures plan, Task 5).
- Produces: the table `public.email_outbox` (columns exactly as in spec §1), the setting `email_notifications_enabled`, and the trigger `document_requests_email`, which runs `public.queue_status_email()`.

- [ ] **Step 1: Write the failing pgTAP test** `07_email_queue.test.sql`

  - **Test email addresses:** they must pass the `residents.email` check, which requires a dot after the `@`, so use `@example.test` addresses.
  - **Setup, as `postgres`:**
    - residents `ana`, `ben`, `cy`, `dee`, `eve`, `fay` (`<name>@example.test`). Each case below uses its own resident so it can't collide with the one-open-request-per-type index.
    - staff `sec@example.test` (secretary), `treasurer@example.test` (treasurer) and `admin@example.test` (admin)
    - `update system_settings set value = 'true' where key = 'email_notifications_enabled'`
  - **Tests:**
    - `has_table('public', 'email_outbox')`; RLS is on (`pg_class.relrowsecurity`).
    - **For payment:** `r1 := request_in(ana, 'BC', 'under_review')`, then `update document_requests set status = 'for_payment' where id = r1`. Exactly 1 outbox row for `r1`, with `kind = 'for_payment'`, `to_email = 'ana@example.test'`, `status = 'queued'` and `attempts = 0`. The `subject` matches `^REQ-[0-9]{4}-[0-9]{6}: Pay PHP 50\.00 for your Barangay Clearance$`.
    - **Ready for release:** `r2 := request_in(ben, 'COR', 'processing')`, then update it to `ready_for_release`. 1 row; `kind = 'ready_for_release'`; the `body` contains `bring a valid ID`.
    - **Rejected through the real workflow:** `r3 := request_in(cy, 'COR', 'under_review')`. As `sec`, `transition_request(r3, 'rejected', 'Missing proof of residency')`. 1 row; `kind = 'rejected'`; the `body` contains `Missing proof of residency`.
    - **No email for other statuses:** `r4 := request_in(dee, 'COR', 'pending')` goes `under_review` → `processing` → `released`, setting `released_at = now()` and `released_to = 'Dee'` in the last update. 0 rows.
    - **No email when the status doesn't change:** updating `purpose_details` on `r1` doesn't add a row (still 1).
    - **No email when the resident has no email:** set `residents.email = null` for `eve`, then `request_in(eve, 'BC', 'under_review')` → `for_payment`. 0 rows.
    - **No email when switched off:** set the setting to `false`, then `request_in(fay, 'BC', 'under_review')` → `for_payment`. 0 rows. Set it back to `true`.
    - **Who can read the outbox:** as `ana`, `select count(*) from email_outbox` = 0, and as `treasurer` it is also 0. As `admin` it is 3.
    - **Nobody else can write it:** as `sec`, `insert into email_outbox …` throws `42501`.

- [ ] **Step 2: Run it and see it fail**

  Run: `npm run test:db`
  Expected: FAIL. `07_email_queue` reports `has_table` not ok, because the table doesn't exist yet.

- [ ] **Step 3: Write `sql/09_email_outbox.sql` (part 1)**

  - **The table:** as in the spec. Use `check` constraints for `kind`, `status`, `char_length(subject) <= 150` and `char_length(last_error) <= 500`, and add the index on `(status, created_at)`.
  - **Access:**
    - `alter table … enable row level security`
    - `revoke all on public.email_outbox from anon, authenticated`
    - `grant select on public.email_outbox to authenticated`
    - policy `email_outbox_select_admin`: `for select to authenticated using (public.has_role('admin'))`
  - **The setting:** `insert into system_settings (key, value, description) values ('email_notifications_enabled', 'false', 'Email residents when a request needs them to act: true or false') on conflict (key) do nothing`.
  - **`queue_status_email()` returns trigger.**
    - Exit at once unless `coalesce((select value from system_settings where key = 'email_notifications_enabled') = 'true'::jsonb, false)`.
    - Look up the resident (`first_name`, `email`) and the document type `name`; exit if `email` is null.
    - The barangay name, office hours and hall address come from `system_settings`, read with `value #>> '{}'`.
    - Insert one row with `left(subject, 150)`.
  - **The trigger:** `create trigger document_requests_email after update of status on public.document_requests for each row when (old.status is distinct from new.status and new.status in ('for_payment','ready_for_release','rejected')) execute function public.queue_status_email()`, preceded by `drop trigger if exists`.

  **Exact email copy.** `{…}` are values; `{fee}` is `to_char(fee_amount, 'FM999,990.00')`. Lines are separated by `E'\n'`. Leave out any line whose value is empty.

  | kind | subject | body lines after `Good day, {first_name},` and a blank line |
  |---|---|---|
  | `for_payment` | `{control_no}: Pay PHP {fee} for your {type}` | `Your request for {type} ({control_no}) is ready for payment.` / `Please pay PHP {fee} at the Treasurer's window of the barangay hall.` / `Office hours: {office_hours}` |
  | `ready_for_release` | `{control_no}: Your {type} is ready for pickup` | `Your {type} ({control_no}) is ready.` / `Pick it up at the barangay hall: {hall_address}` / `Office hours: {office_hours}` / `Please bring a valid ID.` |
  | `rejected` | `{control_no}: Your {type} request was not approved` | `Your request for {type} ({control_no}) was not approved.` / `Reason: {rejection_reason}` / `You may file a new request after addressing the reason above.` |

  Every body ends with a blank line, `Barangay {barangay_name}`, and `This is an automatic message from e-Dokumento. Replies to this email are not read.`

- [ ] **Step 4: Run it and see it pass**

  Run: `npm run test:db`
  Expected: `All tests successful.` Files 01–06 still pass.

- [ ] **Step 5: Commit**

```bash
git add sql/09_email_outbox.sql supabase/tests/07_email_queue.test.sql tests/sql/run.sh
git commit -m "Queue an email when a request needs the resident to act" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Mailer

**Files:**
- Create: `includes/mailer.php`, `tests/Unit/MailerTest.php`
- Modify: `includes/bootstrap.php` (require `includes/mailer.php` after `env.php`), `tests/bootstrap.php` (require it as well), `.env.example` (add the three variables, commented as Gmail settings, with `MAIL_FROM_NAME=Barangay San Isidro e-Dokumento`)

**Interfaces:**
- Produces:
  - `mail_configured(): bool`: true when `MAIL_USERNAME` and `MAIL_APP_PASSWORD` are both non-empty (`Env::get`).
  - `build_message(string $from, string $fromName, string $to, string $subject, string $body, ?DateTimeImmutable $now = null): string`: the full RFC 5322 message with CRLF line endings. It throws `InvalidArgumentException('Invalid recipient address.')` when `$to` fails `filter_var(FILTER_VALIDATE_EMAIL)` or contains `\r`, `\n`, `<` or `>`.
  - `send_mail(string $to, string $subject, string $body): void`: throws `RuntimeException` with the cURL error text when sending fails, and `RuntimeException('Email is not configured.')` when `!mail_configured()`.

- [ ] **Step 1: Write the failing `MailerTest`**

  - **`test_builds_required_headers`:**
    - `build_message('b@gmail.com', 'Barangay San Isidro e-Dokumento', 'ana@example.com', 'Hi', "Body", new DateTimeImmutable('2026-10-09 08:00:00+08:00'))` contains these lines:
      - `Date: Fri, 09 Oct 2026 08:00:00 +0800`
      - `To: ana@example.com`
      - `MIME-Version: 1.0`
      - `Content-Type: text/plain; charset=UTF-8`
      - `Content-Transfer-Encoding: base64`
      - a `Message-ID: <…@gmail.com>` line
      - a `From:` line that ends with `<b@gmail.com>`
    - Every line ends with `\r\n`, and the headers and body are separated by `\r\n\r\n`.
  - **`test_body_is_base64_utf8`:** for the body `"Bayad: ₱50.00\nPeña St."`, base64-decoding the part after the blank line (newlines stripped) gives `"Bayad: ₱50.00\r\nPeña St."`, with lone `\n` turned into `\r\n` before encoding.
  - **`test_long_utf8_subject_is_folded_and_round_trips`:**
    - The subject is `'REQ-2026-000133: Your Certificate of Indigency for Señor Niño Dela Peña is ready for pickup ₱'`.
    - Every header line is ≤ 78 bytes.
    - Running `iconv_mime_decode` (or `mb_decode_mimeheader`) on the unfolded `Subject:` header gives back the exact subject.
  - **`test_subject_newlines_cannot_inject_headers`:** the subject `"Hi\r\nBcc: evil@x.com"` produces no line starting with `Bcc:`, and the decoded subject is `'Hi  Bcc: evil@x.com'`.
  - **`test_rejects_recipient_with_newline`:** `"ana@example.com\r\nBcc: x@y.z"` and `'not-an-email'` both throw `InvalidArgumentException`.
  - **`test_mail_configured_needs_username_and_password`:** with `putenv` it is false with neither variable set, false with only one set, and true with both. Restore the environment in `tearDown`.
  - **`test_send_mail_refuses_when_not_configured`:** with the variables unset, `send_mail(...)` throws `Email is not configured.`

- [ ] **Step 2: Run it and see it fail**

  Run: `npm run test:php -- --filter MailerTest`
  Expected: FAIL, `Call to undefined function build_message()`.

- [ ] **Step 3: Write `includes/mailer.php`**

  - **Headers:** fold the subject with `mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")` after replacing `\r` and `\n` with spaces. The `From` display name is encoded the same way. The `Message-ID` is `<` + `bin2hex(random_bytes(16))` + `@` + the sender's domain + `>`.
  - **Body:** `chunk_split(base64_encode($body), 76, "\r\n")`.
  - **Sending:** `send_mail` uses its **own** handle, `curl_init()`, never the shared handle in `Supabase`, with:
    - `CURLOPT_URL => 'smtps://smtp.gmail.com:465'`
    - `CURLOPT_USERNAME` and `CURLOPT_PASSWORD` from the environment
    - `CURLOPT_MAIL_FROM => "<{$user}>"` and `CURLOPT_MAIL_RCPT => ["<{$to}>"]`
    - `CURLOPT_UPLOAD => true`, and `CURLOPT_READFUNCTION` serving the message string in chunks
    - `CURLOPT_CONNECTTIMEOUT => 5` and `CURLOPT_TIMEOUT => 10`
  - **Display name:** `MAIL_FROM_NAME` defaults to `'Barangay ' . barangay_name() . ' e-Dokumento'` when the variable is empty.

- [ ] **Step 4: Run it and see it pass**

  Run: `npm run test:php`
  Expected: `OK`. Earlier suites still pass.

- [ ] **Step 5: Commit**

```bash
git add includes/mailer.php includes/bootstrap.php tests/bootstrap.php tests/Unit/MailerTest.php .env.example
git commit -m "Add a Gmail SMTP mailer over cURL" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Claiming, sending and the RPC hook

**Files:**
- Modify: `sql/09_email_outbox.sql` (add the three functions), `includes/supabase.php` (`rpc()`), `includes/bootstrap.php` and `tests/bootstrap.php` (require `includes/email_outbox.php` after `mailer.php`)
- Create: `includes/email_outbox.php`, `supabase/tests/08_email_send.test.sql`, `tests/Unit/EmailOutboxTest.php`

**Interfaces:**
- Consumes: `send_mail`, `mail_configured` (Task 3); `email_outbox` (Task 2).
- Produces, SQL:
  - `claim_outbox_emails(p_limit integer default 5) returns table (id uuid, to_email text, subject text, body text)`
  - `finish_outbox_email(p_id uuid, p_ok boolean, p_error text default null) returns void`
  - `retry_outbox_email(p_id uuid) returns void`

  Grant all three `execute … to authenticated` and revoke them from `public` and `anon`. The first two raise `'Only barangay staff can send email notifications.'` unless `public.is_staff()`. `retry` raises `'Only the Administrator can retry emails.'` unless `public.has_role('admin')`, and `'Only failed emails can be retried.'` when the row isn't `failed`.
- Produces, PHP:
  - `const OUTBOX_TRIGGER_FUNCTIONS = ['transition_request', 'record_payment', 'issue_document', 'void_payment'];` The spec names the first three. `void_payment` is added because it moves a request back to `for_payment`, which queues an email.
  - `flush_outbox(callable $rpc, callable $send = 'send_mail', float $budgetSeconds = 12.0): int` returns the number sent. `$rpc` is `fn(string $function, array $params): mixed`, and `$send` is `fn(string $to, string $subject, string $body): void`.
  - `outbox_after_rpc(string $function, callable $rpc, bool $isStaff, bool $configured, callable $send = 'send_mail'): void`

- [ ] **Step 1: Write the failing pgTAP test** `08_email_send.test.sql`

  - **Setup:** turn the setting on. Create `ana@example.test`, `sec`, `treasurer` and `admin` as in Task 2. Queue two rows for `ana` by moving `request_in(ana, 'BC', 'under_review')` and then `request_in(ana, 'COI', 'processing')` to `for_payment` and `ready_for_release` respectively, so the BC row is older.
  - **Tests:**
    - As `sec`, `claim_outbox_emails(1)` returns 1 row (the oldest), whose outbox `status` is now `sending` with `claimed_at` not null. Claiming again returns the other row, and claiming a third time returns 0 rows. No row comes back twice.
    - **Reclaim:** as `postgres`, set the first row's `claimed_at = now() - interval '11 minutes'`. As `sec`, `claim_outbox_emails(5)` returns exactly that row.
    - `finish_outbox_email(id, true)` → `status 'sent'`, `sent_at` not null.
    - `finish_outbox_email(id2, false, repeat('x', 600))` → `status 'queued'`, `attempts 1`, `char_length(last_error) = 500`.
    - After four more claim-and-fail rounds, the row has `attempts 5` and `status 'failed'`, and a further `claim_outbox_emails(5)` doesn't return it.
    - As `ana`: `claim_outbox_emails(1)` throws `P0001` `Only barangay staff can send email notifications.`
    - As `sec`: `retry_outbox_email(id2)` throws `Only the Administrator can retry emails.`
    - As `admin`: `retry_outbox_email(id2)` → `status 'queued'`, `attempts 0`, and `audit_logs` gains a row with `action = 'email_retry'` and `entity_id = id2::text`. Retrying the `sent` row throws `Only failed emails can be retried.`
    - As anon: `claim_outbox_emails(1)` throws `42501`.

- [ ] **Step 2: Write the failing `EmailOutboxTest`**

  Use fakes: an `$rpc` closure that records its calls and serves rows from an array, one per `claim_outbox_emails` call, and a `$send` closure.

  - **`test_flush_sends_each_claimed_row_and_reports_success`:** with 2 rows queued, it returns 2. The calls are `claim(p_limit 1)`, `finish(ok true)`, `claim`, `finish`, `claim` (which returns empty). `$send` gets the row's `to_email`, `subject` and `body`.
  - **`test_flush_reports_failure_with_message`:** `$send` throws `RuntimeException('535 bad credentials')`. Expect `finish` with `p_ok false` and `p_error '535 bad credentials'`, and the return value is 0.
  - **`test_flush_stops_starting_sends_after_budget`:** with `$budgetSeconds = 0.05` and a `$send` that sleeps for 60 ms, only 1 send happens even though 3 rows are queued.
  - **`test_after_rpc_skips_non_staff_and_other_functions`:** `$rpc` records no calls in each of these cases:
    - `('transition_request', isStaff false, configured true)`
    - `('claim_outbox_emails', true, true)`
    - `('submit_request', true, true)`
    - `('issue_document', true, configured false)`
  - **`test_after_rpc_swallows_every_failure`:** `$rpc` throws `SupabaseException('boom', 500)` on claim. `outbox_after_rpc('issue_document', …, true, true)` returns normally. A `$send` that throws `Error` is also contained.

- [ ] **Step 3: Run both and see them fail**

  Run: `npm run test:db` and `npm run test:php -- --filter EmailOutboxTest`
  Expected: FAIL. The functions don't exist (`42883`), and the PHP fails with `Call to undefined function flush_outbox()`.

- [ ] **Step 4: Implement**

  - **SQL `claim_outbox_emails`:**
    1. Return to `queued` any rows that are `sending` with `claimed_at < now() - interval '10 minutes'`.
    2. Mark claimed rows with `update … set status = 'sending', claimed_at = now() where id in (select id from email_outbox where status = 'queued' order by created_at limit p_limit for update skip locked) returning id, to_email, subject, body`.
  - **SQL `finish_outbox_email`:** apply the rules in spec §1.
  - **SQL `retry_outbox_email`:** update the row, then `perform public.write_audit('email_retry', 'email_outbox', p_id::text, '{}'::jsonb)`.
  - **PHP `flush_outbox`:** loop `claim_outbox_emails(['p_limit' => 1])` until a claim comes back empty or `microtime(true) - $start >= $budgetSeconds`. Wrap each send in `try`/`catch (Throwable)` and record the result with `finish_outbox_email`.
  - **PHP `outbox_after_rpc`:** return unless `in_array($function, OUTBOX_TRIGGER_FUNCTIONS, true) && $isStaff && $configured`. Then call `flush_outbox` inside `try { … } catch (Throwable $e) { error_log('outbox: ' . $e->getMessage()); }`.
  - **`Supabase::rpc()`:** store the decoded result first. Then, only when `$this->accessToken !== null && !$this->useSecret`, call `outbox_after_rpc($function, fn(string $f, array $p): mixed => $this->rpc($f, $p), is_staff(), mail_configured())`. Return the stored result, so the value the caller gets never changes.

- [ ] **Step 5: Run everything and see it pass**

  Run: `npm test`
  Expected: PHPUnit `OK`, then `All tests successful.`

- [ ] **Step 6: Commit**

```bash
git add sql/09_email_outbox.sql includes/email_outbox.php includes/supabase.php includes/bootstrap.php tests/bootstrap.php tests/Unit/EmailOutboxTest.php supabase/tests/08_email_send.test.sql
git commit -m "Send queued status emails after staff actions" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Email notifications card on Settings

**Files:**
- Modify: `pages/settings/index.php`

**Interfaces:**
- Consumes: `mail_configured()`, `send_mail()` (Task 3); `retry_outbox_email` (Task 4); the existing `csrf_field()`, `flash_success()`, `flash_error()`, `db_error()`, `action_button()`, `simple_badge()`, `fmt_datetime()`, `e()`, `redirect()`, `Auth::user()['email']`.

- [ ] **Step 1: Branch the POST handler on a hidden `form` field**

  - **`form=email_toggle`:** upsert `email_notifications_enabled` with the value `true` or `false` (as JSON booleans) from a checkbox. Flash `Email notifications turned on.` or `Email notifications turned off.`, then redirect to `/settings`.
  - **`form=email_test`:** `send_mail(Auth::user()['email'], 'e-Dokumento test email', "This is a test from the Settings page.\nIf you can read this, status emails will work.")`. Flash `Test email sent to {email}.`, or `Test email failed: {message}` on any `Throwable`.
  - **`form=email_retry`:** validate `id` with `is_uuid` and call `rpc('retry_outbox_email', ['p_id' => $id])`. Flash `Email queued again. It will be sent after the next staff action.`, or `db_error($e)` on failure.
  - **Anything else:** the existing barangay-details form, unchanged.

- [ ] **Step 2: Render the card below the existing form**

  - **Heading and switch:** a `panel` with the heading `Email notifications`, a switch labelled `Email residents when a request needs payment, is ready for pickup, or is rejected`, and a `Save` button.
  - **Warning:** when the switch is on and `!mail_configured()`, show a warning: `Email is switched on, but MAIL_USERNAME and MAIL_APP_PASSWORD are not set in Vercel. Emails will wait in the queue.`
  - **Test button:** a `Send test email` button. It's disabled, with the hint `Set MAIL_USERNAME and MAIL_APP_PASSWORD first.`, when `!mail_configured()`.
  - **Table:** `select('email_outbox', [['select', 'id,to_email,kind,status,attempts,last_error,created_at,document_requests(control_no)'], ['order', 'created_at.desc'], ['limit', '50']])`.
    - Columns: Created, Recipient, Request, Kind (`For payment` / `Ready for release` / `Rejected`), Status (`simple_badge` with the tones queued→`neutral`, sending→`info`, sent→`success`, failed→`danger`), Attempts, Last error.
    - `failed` rows show `action_button('/settings', ['form' => 'email_retry', 'id' => …], 'Retry', 'btn-sm btn-outline-primary')`.
    - With no rows, show `empty_state('No emails yet', 'Emails appear here once the switch is on and a request needs the resident to act.', '', '', 'envelope')`.
  - **Escaping:** every value goes through `e()`.

- [ ] **Step 3: Check it in the browser**

  Run `npm run dev` with a local `.env` that points at a **non-production** Supabase project with `sql/01`–`05` and `09` applied. Sign in as the Administrator.
  Expected:
  - the card shows
  - flipping the switch persists and flashes a message
  - with no `MAIL_*` set, the warning shows and the test button is disabled
  - with them set, `Send test email` delivers to the Administrator's inbox
  - after a secretary rejects a request with an email, the table shows the row as `sent`
  - with a wrong `MAIL_APP_PASSWORD`, the row shows `queued`, `attempts 1` and the Gmail error, and the rejection itself still succeeded with its normal success message

  If there's no non-production project, stop and ask the person for one. Don't point local development at the live project for this check.

- [ ] **Step 4: Commit**

```bash
git add pages/settings/index.php
git commit -m "Add the Email notifications card to Settings" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Documentation, rollout and the end-to-end check

**Files:**
- Modify: `README.md`, `docs/TESTING.md`, `docs/TEST-REPORT.md`

- [ ] **Step 1: Update the README**

  - Add `sql/09_email_outbox.sql` (order 9, "Email outbox, queueing trigger and sending functions") under the SQL table, as an optional file, with a note that it is safe to rerun.
  - Add the three `MAIL_*` variables to the environment variables section.
  - Add a section, **Turn on status emails**: create the Gmail app password, set the three variables in Vercel (Production), redeploy, open Settings → Email notifications, send a test, then turn the switch on.
  - Under Known limitations, change "Notifications are in-app only." to "Notifications are in-app; residents with an email address also get an email when a request needs payment, is ready for pickup, or is rejected (Gmail, about 500 a day)."

- [ ] **Step 2: Update the test checklist**

  - `docs/TESTING.md`, new **Email** section:
    - "Each of For payment, Ready for release and Rejected queues one email" — auto: `supabase/tests/07_email_queue.test.sql`
    - "Failed sends retry and stop at 5 attempts" — auto: `supabase/tests/08_email_send.test.sql`
    - "Message headers and UTF-8 encoding" — auto: `tests/Unit/MailerTest.php`
    - "A real request taken through all three statuses emails a test inbox with the right control number, fee, reason and office hours" — manual
  - `docs/TEST-REPORT.md`: add row `E1` for the manual check, marked `Open`.

- [ ] **Step 3: Roll out (the person confirms each step)**

  1. Apply `sql/09_email_outbox.sql` to the live project in the SQL Editor. The switch is off by default, so nothing changes for users.
  2. Set the `MAIL_*` variables in Vercel Production, push `main` and wait for the deployment to be `Ready`.
  3. Send the test email from Settings.
  4. Turn the switch on.

- [ ] **Step 4: The end-to-end check**

  As the Test Resident (with the email set to an inbox the person can read), file a BC. Then, as the test staff accounts, take it to For payment, then (after payment and approval) Ready for release, and file and reject a second request.
  Expected: three emails arrive, each with the right control number; the fee `PHP 50.00`; the office hours; and the rejection reason. Record the result as `E1` in `TEST-REPORT.md`: Pass with the times received, or Fail with what was wrong.

- [ ] **Step 5: Commit**

```bash
git add README.md docs/TESTING.md docs/TEST-REPORT.md
git commit -m "Document status emails and record the end-to-end check" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
