# Test report

Results of running the checklist in `docs/TESTING.md` against production.

| | |
| --- | --- |
| **System** | e-Dokumento, Barangay Isca |
| **URL** | https://e-dokumento.vercel.app |
| **Database** | Supabase project `e-Dokumento` (`fsnhbzytspezsygvqxlr`) |
| **Data** | Starting data from `sql/05_seed.sql`, test accounts from `sql/07_test_accounts.sql`, demo data from `sql/08_demo_data.sql` |
| **Date** | October 9, 2026 |

**Result key:** **Pass** · **Fail** · **Open** (not run yet) · **Manual** (needs a person, e.g. checking an inbox)

**How the database checks were run:** each check ran inside one transaction that ended by raising an error on purpose. Every change the check made was rolled back, so production data was not altered. Checks "as a role" set the signed-in user the same way Supabase does for a real request (`request.jwt.claims`), so RLS and the workflow functions saw a real user of that role.

## Summary

| Section | Pass | Fail | Open | Manual |
| --- | --- | --- | --- | --- |
| Authentication and authorization | 8 | 0 | 0 | 1 |
| Create | 6 | 0 | 0 | 0 |
| Read | 3 | 0 | 0 | 0 |
| Update | 4 | 0 | 0 | 0 |
| Delete, deactivate, cancel, void | 4 | 0 | 0 | 0 |
| Relationships | 3 | 0 | 0 | 0 |
| Dashboard, reports, audit | 3 | 0 | 0 | 1 |
| Deployment | 2 | 0 | 0 | 1 |
| Certificate QR code | 1 | 0 | 0 | 0 |
| **Total** | **34** | **0** | **0** | **3** |

The three Manual checks are for a person: the one-hour session refresh (A5), opening the CSV export in Excel (DR3) and the links in confirmation and reset emails (DP2).

**Left on production by testing:** request REQ-2026-000133 (Certificate of Indigency, filed online by Test Resident, Pending) from C1; REQ-2026-000129 moved from Pending to Under review in DR1; Kenneth Tan's occupation changed in U4. Purok 7 and the Test Resident account were deactivated for D4 and A3 and reactivated straight after.

## Findings

1. **ID verification is switched off on production.** `system_settings.require_id_verification` is `off`; `sql/06_id_verification_toggle.sql` added it for testing. With it off, unverified residents can file online requests. With it on, they are refused (C2). Turn it back on before the defense unless the panel should see testing mode:
   `update public.system_settings set value = '"on"' where key = 'require_id_verification';`
2. **The `X-Powered-By: PHP/8.3.8` header reveals the PHP version.** Low risk. `header_remove('X-Powered-By');` in `includes/bootstrap.php` would drop it.
3. **Two accounts have placeholder names.** The residents list and Users page show "ADAZXZAS, NJSAHBXJXA" (`jeztempest@gmail.com`, Resident) and "dadads, adada" (`jezreelblanza480@gmail.com`, Secretary). They are real accounts, so rename them rather than delete them.
4. **Some demo steps are timed outside office hours** (for example "Under review 2:22 AM"). Fixed: `sql/08_demo_data.sql` now moves every step into Monday to Friday, 8:00 AM to 5:00 PM, and the data on production was updated the same way (225 steps, none outside office hours, none out of order). As a result, more open demo requests are now past their target, and the dashboard shows 8 overdue.

## Authentication and authorization

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| A1 | Valid login reaches the dashboard for each of the five roles | Dashboard for each role | Administrator, Punong Barangay, Secretary and Treasurer each reached their dashboard with their own menu; the Resident reached the resident menu (Request a document, My requests). The Punong Barangay can open the Audit log and is refused `/users` (403). | Pass |
| A2 | Wrong password and unknown email | Both show "Incorrect email or password." | POST /login with a valid CSRF token: `nobody.unknown@example.test` and `resident.test@example.test` with a wrong password both returned "Incorrect email or password." | Pass |
| A3 | A deactivated user cannot sign in | Sign-in refused | The Administrator deactivated Test Resident (prompt: "Deactivate Test Resident? They will be signed out and cannot sign in."; result: "Account deactivated. The user can no longer sign in."). Signing in with its password was refused with "Incorrect email or password." After reactivation, the same password signed in, so the refusal was the deactivation and not a typo. | Pass |
| A4 | Session survives a refresh and a new deployment; Sign out ends it | Still signed in; signed out after Sign out | As the Secretary: after a reload the dashboard still showed "Test Secretary". After Sign out, opening `/dashboard` went to Sign in. The new-deployment part was not run, because it needs a redeploy. | Pass |
| A5 | Session refreshes silently after an hour | No sign-in prompt after the access token expires | | Manual |
| A6 | Opening `/residents` while signed out | Redirect to Sign in | `303` to `/login?next=%2Fresidents` | Pass |
| A7 | Opening `/users` as a Secretary | Access Denied page | "403 · You don't have access to this page" | Pass |
| A8 | With a resident's token, `residents` returns only their own row (RLS) | 1 row | As `authenticated` with the test resident's claims: 1 of 27 resident rows visible, 0 audit log rows visible | Pass |
| A9 | Form submitted with a forged token | "This form expired" | POST /login with `_csrf=forged`: HTTP 419, "This form expired" | Pass |

## Create

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| C1 | A verified resident submits a request and receives a control number | Control number shown | Test Resident filed a Certificate of Indigency online with a PDF ID: the page opened REQ-2026-000133, Pending, with `test-id.pdf` attached and "Submitted online" on the timeline. The account is not verified; it could file because ID verification is off (Finding 1). | Pass |
| C2 | An unverified resident requests online | Refused | With `require_id_verification` on: "Your residency must be verified before you can request documents online." (see Finding 1) | Pass |
| C3 | A second open request for the same document | Refused | "There is already an open request for Barangay Clearance. Track it in Requests." | Pass |
| C4 | Missing required file, 3 MB file, .docx | Each refused | Missing: "Upload the required files: Valid government-issued ID, Proof of residency." 3 MB: "Each attachment must be 2 MB or smaller." .docx type: "Attachments must be PDF, JPG or PNG files." In the browser, a Word file renamed to `.pdf` was posted from the request form and refused by the server: "Valid government-issued ID must be a PDF, JPG or PNG file." (`includes/upload.php` checks the file's first bytes, not its name.) | Pass |
| C5 | A resident with the same name and birth date as an existing one | Refused | `23505` unique violation on `residents_identity_uniq` | Pass |
| C6 | A second First Time Jobseeker request after one was released | Refused | "First Time Jobseeker Certification can only be issued once per resident." | Pass |

## Read

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| R1 | Requests and Residents search, filter, sort and paginate | Correct rows for each | Requests: 45 rows over 3 pages of 15; search "valencia" 2 rows; status For payment 2; type Business Clearance 5; sort by control number ascending starts 001, 002, 089…; overdue filter 4, matching the dashboard card. Residents: 27 rows over 2 pages; search "cruz" 2 (Cruz, Dela Cruz); sort by birth date ascending starts with the oldest (born 1949). | Pass |
| R2 | Request details show the full timeline with who changed each status | Every step with its actor | REQ-2026-000096: Pending and Under review (Test Secretary), For payment (Test Secretary), Processing "Paid, OR 1001206" (Test Treasurer), For approval (Test Secretary), Ready for release "Approved by the Punong Barangay" (Test Punong Barangay), Released "Claimed by Teresita Valdez" (Test Secretary), plus the payment and the issued document | Pass |
| R3 | `/verify` with a valid, expired, revoked and unknown code | Four distinct results | `0A5B1E96C9` "Genuine and valid"; `17E6DC3E5B` "Genuine, but expired"; `7D5D047046` "Revoked by the barangay"; `ABCDEF0123` "No certificate has this code" | Pass |

## Update

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| U1 | Each lifecycle transition works for its role and adds a history row | | `08_demo_data.sql` moved 43 requests through every transition by calling the workflow functions as the Secretary, Treasurer and Punong Barangay. History rows: new→pending 43, pending→under_review 39, under_review→for_payment 26, for_payment→processing 24, under_review→processing 10, processing→for_approval 25, for_approval→ready_for_release 22, processing→ready_for_release 7, ready_for_release→released 26, rejected 2, cancelled 1. Every request's latest history row matches its status (0 mismatches). | Pass |
| U2 | A resident moves their own request to Released | Database refuses | `transition_request(…, 'released')` as the test resident: "That status change is not allowed for your role at this stage." | Pass |
| U3 | Payment with a wrong amount, or a reused OR number | Both refused | Wrong amount: "Amount must be exactly PHP 50.00." Reused OR: "OR number 1 has already been used." | Pass |
| U4 | Editing a resident changes the row in Supabase | Row changed | As the Secretary, changed Kenneth Tan's occupation to "Online seller and reseller": the page said "Resident record updated." The row in Supabase has the new value, and the audit log recorded `occupation` from "Online seller" to "Online seller and reseller". | Pass |

## Delete, deactivate, cancel, void

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| D1 | Every destructive button asks for confirmation; reject, void and revoke ask for a reason | Confirmation; reason where needed | Cancel walk-in request: confirmation. Reject request: confirmation plus reason; with an empty reason the dialog said "Write a reason of at least 10 characters" and did not submit, and Go back left the request unchanged. Revoke (15 on the Issued documents page): confirmation plus reason. Void payment (Treasurer, REQ-2026-000125): "Void OR 1001224? The request returns to For payment." plus reason; Go back left the payment posted and the request in Processing. Deactivating a user also asks for confirmation. | Pass |
| D2 | Cancelled, voided and revoked rows stay with their reason | Rows kept | Voiding a payment kept the row as `voided / Wrong OR booklet used for this receipt.` Rows kept: 1 cancelled request, 2 rejected requests with reasons, 1 revoked certificate with its reason | Pass |
| D3 | A requirement linked to a document type cannot be deleted | Refused | `23503` foreign key violation from `document_type_requirements` | Pass |
| D4 | A deactivated purok disappears from forms but stays on existing residents | Gone from forms; kept on residents | Deactivated Purok 7 on Reference data: the registration form listed Purok 1 to 6 only, and its 3 residents (Dizon, Flores, Robles) still showed Purok 7. Reactivated straight after; the form lists Purok 1 to 7 again. | Pass |

## Relationships

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| RL1 | Insert a request with a random `resident_id` | Foreign key error | `23503` on `document_requests_resident_id_fkey` | Pass |
| RL2 | A request accepts only one posted payment and one issued document | Second of each refused | `23505` on `payments_one_posted`; `23505` on `issued_documents_request_id_key` | Pass |
| RL3 | Delete a request that has a payment | Refused (ON DELETE RESTRICT) | `23503` on `payments_request_id_fkey` | Pass |

## Dashboard, reports, audit

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| DR1 | Filing, paying and releasing change the pipeline counts and the chart | Counts move with the request | As the Secretary, Start review on REQ-2026-000129: `/api/dashboard` went from Pending 3 / Under review 2 to Pending 2 / Under review 3 straight away | Pass |
| DR2 | Report totals match `count(*)` for the same filters | Equal | Last 200 days: issuance report 31 issued and PHP 1,990.00 in fees; direct count 31 and PHP 1,990.00 | Pass |
| DR3 | CSV export opens in Excel with ñ and ₱ shown correctly | | | Manual |
| DR4 | Sign-ins, edits, status changes, payments and voids appear in the audit log | Entries present | Audit log has login 70, logout 68, create 135, update 15, status_change 136, payment_post 26, issue 8, approve 23, revoke 1, attachment_accepted 5. No payment has been voided on production; `void_payment` writes a `payment_void` entry. | Pass |

## Deployment

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| DP1 | No secret key in page source or network responses | None | No `sb_secret_`, `service_role` or `SUPABASE_SECRET` in `/login`, `/verify`, 9 signed-in pages, the two JSON endpoints or the 5 page scripts. The browser contacted only e-dokumento.vercel.app, cdn.jsdelivr.net and Google Fonts; it never calls Supabase directly. | Pass |
| DP2 | Confirmation and password reset emails link to the production URL | | | Manual |
| DP3 | Security headers on production | Present | `/login`: `Content-Security-Policy`, `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (see Finding 2) | Pass |

## Certificate QR code

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| Q1 | The printed certificate shows a QR code that opens `/verify` with its code | Scanning opens the matching result | BBC-2026-00068 print view: the QR code is drawn next to "Verify at e-dokumento.vercel.app/verify · Code 5213AE2A83" and encodes `https://e-dokumento.vercel.app/verify?code=5213AE2A83`. That link shows "Genuine and valid". Viewing the page did not change the print count (0). A phone scan of the printed copy is still worth doing once. | Pass |
