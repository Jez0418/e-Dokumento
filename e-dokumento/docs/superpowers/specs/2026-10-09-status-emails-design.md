# Status Emails Design

**Date:** 2026-10-09
**Status:** Approved in conversation; awaiting review of this written spec.

## Purpose

Residents only learn that a request moved by signing in and reading the in-app notifications. This feature emails them when a request needs them to act: pay, pick up, or re-file. It supports the thesis defense first and must also hold up when a barangay runs the system.

## Decisions

| Question | Decision |
| --- | --- |
| Which changes send an email | Only when the resident must act: **For payment**, **Ready for release**, **Rejected** |
| Sender | A Gmail account with an app password, over SMTP. No domain is owned. |
| Architecture | Outbox table filled by a database trigger; PHP sends right after the staff action |
| Retries | The next staff action retries failures. **No cron job**, so the secret key stays on the Users page only |
| Default | Off. An Administrator turns it on |

## Out of scope

SMS, HTML email templates, emails for the other statuses, emails to staff, resident opt-out, a sending domain. In-app notifications stay exactly as they are.

## 1. Data and trigger: `sql/09_email_outbox.sql`

A new file run after `08`. Files `01`–`08` stay unchanged, so the live database only needs `09` applied.

**Table `public.email_outbox`**

| Column | Type | Rule |
| --- | --- | --- |
| `id` | uuid | primary key, `gen_random_uuid()` |
| `request_id` | uuid | references `document_requests (id)` on delete cascade |
| `to_email` | text | not null |
| `kind` | text | `for_payment`, `ready_for_release` or `rejected` |
| `subject` | text | not null, at most 150 characters |
| `body` | text | not null |
| `status` | text | `queued` (default), `sending`, `sent` or `failed` |
| `attempts` | smallint | default 0 |
| `last_error` | text | null until a send fails; at most 500 characters |
| `claimed_at` | timestamptz | set when a row is claimed |
| `created_at` | timestamptz | default `now()` |
| `sent_at` | timestamptz | set on success |

Index on `(status, created_at)`.

**RLS:** enabled. The Administrator can select. No role gets insert, update or delete; only the `security definer` functions below write to the table.

**Setting:** `system_settings` key `email_notifications_enabled`, value `false`, inserted with `on conflict do nothing`.

**Trigger `document_requests_email`:** `after update of status on public.document_requests`, for each row, when `old.status is distinct from new.status` and `new.status in ('for_payment','ready_for_release','rejected')`. The function (`security definer`, `search_path = ''`):

1. Returns without doing anything unless `email_notifications_enabled` is `true`.
2. Reads `residents.email` for `new.resident_id`. Returns if it is null; walk-in residents without email still get the in-app notification only.
3. Builds the subject and plain-text body from the control number, document type name and the barangay name in `system_settings`:
   - **For payment:** the fee amount (`fee_amount`) and the barangay's office hours.
   - **Ready for release:** pickup at the barangay hall during office hours, and a reminder to bring a valid ID.
   - **Rejected:** `rejection_reason`, and that the resident may file a new request.
4. Inserts one `queued` row.

The trigger catches every route to these statuses (`transition_request`, `record_payment`, `issue_document`), and a status change that rolls back queues nothing.

**Functions callable by staff** (`security definer`; raise an exception unless `is_staff()`):

- `claim_outbox_emails(p_limit int default 5)`:
  - Returns any `sending` rows whose `claimed_at` is older than 10 minutes to `queued`.
  - Selects up to `p_limit` `queued` rows, oldest first, `for update skip locked`.
  - Marks them `sending` with `claimed_at = now()` and returns `id, to_email, subject, body`.
- `finish_outbox_email(p_id uuid, p_ok boolean, p_error text default null)`:
  - Success: `status = 'sent'` and `sent_at = now()`.
  - Failure: `attempts = attempts + 1`, `last_error = left(p_error, 500)`, and `status = 'failed'` once `attempts` reaches 5, otherwise `'queued'`.
- `retry_outbox_email(p_id uuid)`: Administrator only. Sets a `failed` row back to `queued` with `attempts = 0`, and writes the audit log.

## 2. Sending

**`includes/mailer.php`:** `send_mail(string $to, string $subject, string $body): void`, which throws `RuntimeException` on failure.

- **Connection:** cURL to `smtps://smtp.gmail.com:465` with `CURLOPT_USERNAME`, `CURLOPT_PASSWORD`, `CURLOPT_MAIL_FROM`, `CURLOPT_MAIL_RCPT`, `CURLOPT_UPLOAD` and a 10-second timeout.
- **Message:** RFC 5322 with headers `Date`, `From` (`MAIL_FROM_NAME <MAIL_USERNAME>`), `To`, `Subject` (`=?UTF-8?B?…?=`), `Message-ID`, `MIME-Version: 1.0`, `Content-Type: text/plain; charset=UTF-8`, `Content-Transfer-Encoding: base64`. A pure function, `build_message()`, builds it so it can be unit tested.
- **Settings:** environment variables `MAIL_USERNAME`, `MAIL_APP_PASSWORD`, `MAIL_FROM_NAME`, read the way the Supabase keys are read. `mail_configured(): bool` reports whether the first two are set.

**`includes/email_outbox.php`:** `flush_outbox(Supabase $db, callable $send = 'send_mail'): void`.

- Returns at once unless `mail_configured()`.
- Calls `claim_outbox_emails`, sends each row, and records each result with `finish_outbox_email`.
- Catches every exception. A failure never reaches the user and never undoes the staff action.

**Hook:** in `Supabase::rpc()`, after a successful call to `transition_request`, `record_payment` or `issue_document` on a user client, call `flush_outbox($this)`. The call uses the signed-in staff member's token. At most 5 emails are sent per action, which adds about 1–2 seconds when emails are waiting.

## 3. Administrator screen

There is a new **Email notifications** card on the Settings page (`pages/settings/index.php`), shown to the Administrator only. It is a form of its own, separate from the barangay details form.

- **On/off switch** for `email_notifications_enabled`, saved with the existing upsert pattern and CSRF check.
- **Warning** when the switch is on but `mail_configured()` is false.
- **Send test email** button. It sends straight to the signed-in Administrator's email and shows success or the error message. Nothing is queued.
- **Table** of the latest 50 outbox rows: created, recipient, control number, kind, status, attempts, last error. `failed` rows have a **Retry** button that calls `retry_outbox_email`.

## 4. Error handling

- **Wrong or missing Gmail settings:** rows stay `queued` and the warning shows; nothing breaks.
- **Gmail refuses or times out:** `last_error` is recorded, and the next staff action retries. After 5 attempts the row is `failed` and waits for a manual Retry.
- **PHP stops partway through a send:** the row stays `sending` and is reclaimed after 10 minutes. In that rare case a resident may get the same email twice; the system never drops an email silently.
- **Two staff act at the same moment:** `skip locked` gives each row to only one of them.

## 5. Testing

1. **Feasibility check before any real code:** a throwaway page on a Vercel preview sends one email through `smtps://smtp.gmail.com:465`. If Vercel's PHP cURL cannot do SMTP, stop and return to design. The page is deleted afterwards.
2. **pgTAP**, added to the automated test plan's database suite:
   - each of the three statuses queues exactly one row
   - other statuses, a resident with no email, and the switch being off queue none
   - a resident and the Treasurer cannot select from `email_outbox`
   - two claims never return the same row
   - the 5th failure marks the row `failed`
   - `retry_outbox_email` is refused for anyone but the Administrator
3. **PHPUnit:**
   - `build_message()` encodes the subject and body (ñ, ₱) and includes the required headers
   - `flush_outbox()` with a fake sender records success and failure and swallows exceptions
4. **Manual check** added to `docs/TESTING.md` and `docs/TEST-REPORT.md`: take a real request through all three statuses with a test inbox, and confirm the three emails arrive with correct details.

**Order:** carry out the existing automated test plan (`docs/superpowers/plans/2026-10-06-automated-tests.md`) first. That needs PHP 8.3 and Docker Desktop installed.

## 6. Documentation

- **README:** add `09_email_outbox.sql` to the SQL table, add the three environment variables, add a short "Turn on status emails" section (create the Gmail app password, set the variables, switch it on, send a test), and change "Notifications are in-app only" under Known limitations.
- **`.env.example`:** add the three variables.
