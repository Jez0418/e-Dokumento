# Captain e-signature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Certificates of document types that need the Punong Barangay's approval carry the captain's signature image, which only the captain can set.

**Architecture:** A private `signatures` Storage bucket holds the image; `barangay_officials.signature_path` points at it. A `security definer` RPC plus a trigger make the captain the only writer of `signature_path` (admins included are blocked). `print.php` stamps the image when a pure helper says so; the verify RPC reports `e_signed`.

**Tech Stack:** PHP 8.1+, Supabase (Postgres, RLS, Storage), PHPUnit, pgTAP.

**Spec:** `docs/superpowers/specs/2026-10-14-captain-esignature-design.md`

## Global Constraints

- SQL: do not edit `sql/01`–`08`. All new SQL goes in `sql/10_captain_signature.sql`; every statement is rerunnable (`if not exists`, `create or replace`, `drop … if exists`); every function is `security definer` with `set search_path = ''`.
- Bucket `signatures`: private, PNG/JPG only, 1 MB (1048576 bytes) max. Object path: `<captain profile uuid>/<24 hex>.<png|jpg>` (matches the `/files` route regex).
- Only `has_role('captain')` may set, replace or remove `signature_path`; admins may not.
- Stamp only when: type `requires_captain_approval` is true, document `status` is not `revoked`, and the signatory has a `signature_path`.
- Footnote when stamped: `Electronically signed by the Punong Barangay. Valid only with the barangay dry seal.` Otherwise the current wet-signature note is unchanged.
- Verify page shows "Electronically signed by <name>"; the image is never public.
- Tests: PHP needs 8.1+ (`npm run test:php`); DB tests run via `npm run test:db` (local Docker only).

## Review Focus

- Admin edits `signature_path` by direct PostgREST `update`/`insert`: must be rejected, while an admin editing `full_name` still works.
- Captain passes a path under another user's folder to the RPC: rejected.
- Upload is a PDF, a non-image renamed `.png`, or over 1 MB: rejected with a clear message, nothing stored.
- Document type without captain approval, or a revoked document, with a signature on file: no stamp and wet-signature note.
- No active Punong Barangay row when the captain saves: clear error, uploaded object removed.

## File Structure

- Create `sql/10_captain_signature.sql`: bucket, storage policies, guard trigger, RPC, `verify_document` update.
- Create `supabase/tests/09_captain_signature.test.sql`: pgTAP tests.
- Modify `tests/sql/run.sh`, `README.md`: apply the new file; document it.
- Modify `includes/upload.php`: `Upload::checkSignature`.
- Modify `includes/helpers.php`: `cert_is_esigned`.
- Modify `pages/files.php`: allow bucket `signatures`.
- Modify `pages/profile/index.php`: captain "My signature" card.
- Modify `pages/documents/print.php`, `pages/public/verify.php`, `assets/css/app.css`: stamping.
- Tests: `tests/Unit/UploadTest.php`, `tests/Unit/HelpersTest.php`.

---

### Task 1: Database — bucket, guard trigger, RPC, verify flag

**Files:**
- Create: `sql/10_captain_signature.sql`, `supabase/tests/09_captain_signature.test.sql`
- Modify: `tests/sql/run.sh` (add `sql/10_captain_signature.sql` after `sql/09_email_outbox.sql`), `README.md` (SQL table row 10, "Captain's e-signature", safe to rerun)

**Interfaces:**
- Produces: `public.set_captain_signature(p_path text) returns void` (null removes); `verify_document` JSON gains `'e_signed'` boolean (`requires_captain_approval and signature_path is not null`); bucket `signatures`.

- [ ] **Step 1: Write `supabase/tests/09_captain_signature.test.sql`** (pgTAP, same setup style as `06_documents.test.sql`, users via `tests.create_staff`, `tests.add_captain()`). Tests, with exact error text `Only the Punong Barangay can change the signature.`:
  1. captain `set_captain_signature('<captain uid>/aaaaaaaaaaaaaaaaaaaaaaaa.png')` → active official's `signature_path` equals it.
  2. captain `set_captain_signature(null)` → null again.
  3. admin, secretary, treasurer each `throws_ok` on the RPC (`P0001`, message above).
  4. admin direct `update public.barangay_officials set signature_path = …` → `throws_ok` same message.
  5. admin direct update of `full_name` → `lives_ok`.
  6. admin direct `insert` of an official with a `signature_path` → `throws_ok`.
  7. captain with a path under another uid's folder → `throws_ok` `That signature file is not in your folder.`
  8. captain with a malformed path (`'x.png'`) → `throws_ok` `That signature file is not valid.`
  9. no active captain official → captain RPC `throws_ok` `Add the active Punong Barangay under Officials first.`
  10. `verify_document(code) ->> 'e_signed'` is `'true'` for a captain-approval type with a signature, `'false'` for a type with `requires_captain_approval = false`, `'false'` with no signature.

- [ ] **Step 2: Run** `npm run test:db`. Expected: new tests FAIL (function/column missing).

- [ ] **Step 3: Write `sql/10_captain_signature.sql`**:
  - bucket insert `('signatures','signatures',false,1048576,array['image/jpeg','image/png'])` `on conflict do nothing`.
  - Storage policies (`drop policy if exists` first): captain inserts/deletes where `bucket_id='signatures' and (storage.foldername(name))[1] = auth.uid()::text and public.has_role('captain')`; any `authenticated` may select `bucket_id='signatures'` (the image is shown on certificates they can view; access is by 60-second signed URL only).
  - `public.guard_signature_path()` trigger function, `before insert or update on public.barangay_officials`: raise `Only the Punong Barangay can change the signature.` when (insert and `new.signature_path is not null`) or (update and `new.signature_path is distinct from old.signature_path`) and not `has_role('captain')`. Name the trigger `barangay_officials_guard_signature`.
  - `set_captain_signature(p_path text)`: require `has_role('captain')` (same message); if `p_path` not null, require match `^[0-9a-f-]{36}/[0-9a-f]{24}\.(png|jpg)$` (`That signature file is not valid.`) and prefix `auth.uid()::text || '/'` (`That signature file is not in your folder.`); update the active `punong_barangay` row, or raise `Add the active Punong Barangay under Officials first.` when none; write an audit entry via `public.write_audit` like `issue_document` does. Grant execute to `authenticated` only.
  - `create or replace` `verify_document` with the current body from `sql/02_functions.sql` (search `create or replace function public.verify_document`) plus `'e_signed'`; join already includes `o` and `t`.

- [ ] **Step 4: Add the file to `tests/sql/run.sh` and run** `npm run test:db`. Expected: all pgTAP files PASS, including existing `06_documents` (it may assert key sets of `verify_document`; adjust only if it does).

- [ ] **Step 5: Add README row, commit**

```bash
git add sql/10_captain_signature.sql supabase/tests/09_captain_signature.test.sql tests/sql/run.sh README.md
git commit -m "Add captain-only signature storage, guard trigger and RPC"
```

### Task 2: PHP helpers — upload check, stamp rule, files route

**Files:**
- Modify: `includes/upload.php`, `includes/helpers.php`, `pages/files.php`
- Test: `tests/Unit/UploadTest.php`, `tests/Unit/HelpersTest.php`

**Interfaces:**
- Produces: `Upload::checkSignature(?array $file): array|string` — same return shape as `Upload::check` (file info array or error message); never null. Label `Signature`.
- Produces: `cert_is_esigned(array $type, string $docStatus, ?string $signaturePath): bool` in `includes/helpers.php`.

- [ ] **Step 1: Write failing tests**
  - `UploadTest`: `test_signature_accepts_png` (PNG magic bytes → array with `ext` `png`); `test_signature_rejects_pdf` (message `Signature must be a PNG or JPG image.`); `test_signature_rejects_over_1mb` (message `Signature must be 1 MB or smaller.`); `test_signature_required` (null file → `Signature is required.`); `test_signature_rejects_text_renamed_png`.
  - `HelpersTest` with a data provider: `['requires_captain_approval' => true]`, `'valid'`, `'sig/x.png'` → true; type flag false → false; status `'revoked'` → false; null path → false; empty-string path → false.

- [ ] **Step 2: Run** `npm run test:php -- --filter 'Upload|Helpers'`. Expected: FAIL (undefined method/function).

- [ ] **Step 3: Implement** both functions. `checkSignature` calls `check($file, 'Signature', true)`, then rejects `application/pdf` and sizes over 1048576 with the messages above; `cert_is_esigned` is a pure boolean expression. In `pages/files.php` add `'signatures'` to the allowed bucket list.

- [ ] **Step 4: Run** `npm run test:php`. Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/upload.php includes/helpers.php pages/files.php tests/Unit/UploadTest.php tests/Unit/HelpersTest.php
git commit -m "Add signature upload check, stamp rule and files bucket"
```

### Task 3: Captain's "My signature" card

**Files:**
- Modify: `pages/profile/index.php`

**Interfaces:**
- Consumes: `Upload::checkSignature`, `Upload::store($db, 'signatures', $user['id'], $file): string`, RPC `set_captain_signature`, `Supabase::removeObjects`, route `url('files', ['bucket' => 'signatures', 'path' => $path])`.

- [ ] **Step 1: Implement** a `_section === 'signature'` POST handler, captain only (`Auth::role() === 'captain'`, else ignore):
  - action `save`: `checkSignature($_FILES['signature'])`; on error show it as `$errors['signature']`. Else `store`, call `rpc('set_captain_signature', ['p_path' => $path])`; on throw, `removeObjects` the new object and show `db_error($e)`; on success remove the previous object (ignore failures), `flash_success('Signature saved.')`, redirect `/profile`.
  - action `remove`: rpc with null, remove the old object, `flash_success('Signature removed.')`.
  - Card in the right column, captain only: current image preview (via the files URL, `max-height: 120px`, alt "Your signature"), file input `accept="image/png,image/jpeg"`, hint "PNG with a transparent background works best. Max 1 MB.", Save/Remove buttons; CSRF field like the other sections. Load the current path with `$db->first('barangay_officials', [['position','eq.punong_barangay'],['is_active','eq.true'],['select','id,signature_path']])`.

- [ ] **Step 2: Verify manually** with `npm run dev`: sign in as a captain test account (`sql/07_test_accounts.sql`) → upload a PNG → preview shows; upload a PDF → error; sign in as admin → no card, and POSTing `_section=signature` changes nothing.

- [ ] **Step 3: Commit**

```bash
git add pages/profile/index.php
git commit -m "Let the captain upload their signature from the profile page"
```

### Task 4: Stamp certificates and the verify page

**Files:**
- Modify: `pages/documents/print.php`, `pages/public/verify.php`, `assets/css/app.css`, `docs/TESTING.md` (one line on the new test file)

**Interfaces:**
- Consumes: `cert_is_esigned`, `verify_document` field `e_signed`.

- [ ] **Step 1: Implement in `print.php`:** add `signature_path` to the `barangay_officials(...)` select; compute `$esigned = cert_is_esigned($type, $doc['status'], $signatory['signature_path'] ?? null)`; when true render `<img class="cert-sign-img" src="<files url>" alt="Signature of the Punong Barangay">` as the first child of `.cert-sign`, and swap the `cert-note` text for the e-signature footnote (Global Constraints).
- [ ] **Step 2: CSS** `.cert-sign-img { display:block; max-height:.9in; margin:0 auto -.35rem; }` next to `.cert-sign` (`app.css:816`); ensure it prints (no `display:none` in the print block).
- [ ] **Step 3: `verify.php`:** when `$result['e_signed']` is true, show `Electronically signed by <signatory>, Punong Barangay` in the "Signed by" row and drop the "wet signature" clause from the closing sentence; otherwise unchanged.
- [ ] **Step 4: Verify** with `npm run dev` and a seeded captain-approval certificate: stamped for a signed captain; no stamp for revoked, for a non-approval type, and after removing the signature; verify page text matches. Then run `npm test`; expected: PHP and DB suites PASS.
- [ ] **Step 5: Commit**

```bash
git add pages/documents/print.php pages/public/verify.php assets/css/app.css docs/TESTING.md
git commit -m "Stamp the captain's e-signature on approved certificates"
```
