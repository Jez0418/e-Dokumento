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
| Authentication and authorization | 4 | 0 | 4 | 1 |
| Create | 5 | 0 | 1 | 0 |
| Read | 1 | 0 | 2 | 0 |
| Update | 3 | 0 | 1 | 0 |
| Delete, deactivate, cancel, void | 2 | 0 | 2 | 0 |
| Relationships | 3 | 0 | 0 | 0 |
| Dashboard, reports, audit | 2 | 0 | 1 | 1 |
| Deployment | 1 | 0 | 1 | 1 |
| Certificate QR code | 0 | 0 | 1 | 0 |
| **Total** | **21** | **0** | **13** | **3** |

## Findings

1. **ID verification is switched off on production.** `system_settings.require_id_verification` is `off`; `sql/06_id_verification_toggle.sql` added it for testing. With it off, unverified residents can file online requests. With it on, they are refused (C2). Turn it back on before the defense unless the panel should see testing mode:
   `update public.system_settings set value = '"on"' where key = 'require_id_verification';`
2. **The `X-Powered-By: PHP/8.3.8` header reveals the PHP version.** Low risk. `header_remove('X-Powered-By');` in `includes/bootstrap.php` would drop it.

## Authentication and authorization

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| A1 | Valid login reaches the dashboard for each of the five roles | Dashboard for each role | | Open |
| A2 | Wrong password and unknown email | Both show "Incorrect email or password." | POST /login with a valid CSRF token: `nobody.unknown@example.test` and `resident.test@example.test` with a wrong password both returned "Incorrect email or password." | Pass |
| A3 | A deactivated user cannot sign in | Sign-in refused | | Open |
| A4 | Session survives a refresh and a new deployment; Sign out ends it | | | Open |
| A5 | Session refreshes silently after an hour | No sign-in prompt after the access token expires | | Manual |
| A6 | Opening `/residents` while signed out | Redirect to Sign in | `303` to `/login?next=%2Fresidents` | Pass |
| A7 | Opening `/users` as a Secretary | Access Denied page | | Open |
| A8 | With a resident's token, `residents` returns only their own row (RLS) | 1 row | As `authenticated` with the test resident's claims: 1 of 27 resident rows visible, 0 audit log rows visible | Pass |
| A9 | Form submitted with a forged token | "This form expired" | POST /login with `_csrf=forged`: HTTP 419, "This form expired" | Pass |

## Create

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| C1 | A verified resident submits a request and receives a control number | Control number shown | | Open |
| C2 | An unverified resident requests online | Refused | With `require_id_verification` on: "Your residency must be verified before you can request documents online." (see Finding 1) | Pass |
| C3 | A second open request for the same document | Refused | "There is already an open request for Barangay Clearance. Track it in Requests." | Pass |
| C4 | Missing required file, 3 MB file, .docx | Each refused | Missing: "Upload the required files: Valid government-issued ID, Proof of residency." 3 MB: "Each attachment must be 2 MB or smaller." .docx type: "Attachments must be PDF, JPG or PNG files." The upload page's content check on a renamed .docx is still to be tried in the browser. | Pass |
| C5 | A resident with the same name and birth date as an existing one | Refused | `23505` unique violation on `residents_identity_uniq` | Pass |
| C6 | A second First Time Jobseeker request after one was released | Refused | "First Time Jobseeker Certification can only be issued once per resident." | Pass |

## Read

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| R1 | Requests and Residents search, filter, sort and paginate | | | Open |
| R2 | Request details show the full timeline with who changed each status | | | Open |
| R3 | `/verify` with a valid, expired, revoked and unknown code | Four distinct results | `0A5B1E96C9` "Genuine and valid"; `17E6DC3E5B` "Genuine, but expired"; `7D5D047046` "Revoked by the barangay"; `ABCDEF0123` "No certificate has this code" | Pass |

## Update

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| U1 | Each lifecycle transition works for its role and adds a history row | | `08_demo_data.sql` moved 43 requests through every transition by calling the workflow functions as the Secretary, Treasurer and Punong Barangay. History rows: new→pending 43, pending→under_review 39, under_review→for_payment 26, for_payment→processing 24, under_review→processing 10, processing→for_approval 25, for_approval→ready_for_release 22, processing→ready_for_release 7, ready_for_release→released 26, rejected 2, cancelled 1. Every request's latest history row matches its status (0 mismatches). | Pass |
| U2 | A resident moves their own request to Released | Database refuses | `transition_request(…, 'released')` as the test resident: "That status change is not allowed for your role at this stage." | Pass |
| U3 | Payment with a wrong amount, or a reused OR number | Both refused | Wrong amount: "Amount must be exactly PHP 50.00." Reused OR: "OR number 1 has already been used." | Pass |
| U4 | Editing a resident changes the row in Supabase | | | Open |

## Delete, deactivate, cancel, void

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| D1 | Every destructive button asks for confirmation; reject, void and revoke ask for a reason | | | Open |
| D2 | Cancelled, voided and revoked rows stay with their reason | Rows kept | Voiding a payment kept the row as `voided / Wrong OR booklet used for this receipt.` Rows kept: 1 cancelled request, 2 rejected requests with reasons, 1 revoked certificate with its reason | Pass |
| D3 | A requirement linked to a document type cannot be deleted | Refused | `23503` foreign key violation from `document_type_requirements` | Pass |
| D4 | A deactivated purok disappears from forms but stays on existing residents | | | Open |

## Relationships

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| RL1 | Insert a request with a random `resident_id` | Foreign key error | `23503` on `document_requests_resident_id_fkey` | Pass |
| RL2 | A request accepts only one posted payment and one issued document | Second of each refused | `23505` on `payments_one_posted`; `23505` on `issued_documents_request_id_key` | Pass |
| RL3 | Delete a request that has a payment | Refused (ON DELETE RESTRICT) | `23503` on `payments_request_id_fkey` | Pass |

## Dashboard, reports, audit

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| DR1 | Filing, paying and releasing change the pipeline counts and the chart | | | Open |
| DR2 | Report totals match `count(*)` for the same filters | Equal | Last 200 days: issuance report 31 issued and PHP 1,990.00 in fees; direct count 31 and PHP 1,990.00 | Pass |
| DR3 | CSV export opens in Excel with ñ and ₱ shown correctly | | | Manual |
| DR4 | Sign-ins, edits, status changes, payments and voids appear in the audit log | Entries present | Audit log has login 70, logout 68, create 135, update 15, status_change 136, payment_post 26, issue 8, approve 23, revoke 1, attachment_accepted 5. The void is checked on the Audit page when the browser checks run. | Pass |

## Deployment

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| DP1 | No secret key in page source or network responses | None | `sb_secret` not found in `/login` or `/verify` page source. Signed-in pages and network responses are still to be checked in the browser. | Open |
| DP2 | Confirmation and password reset emails link to the production URL | | | Manual |
| DP3 | Security headers on production | Present | `/login`: `Content-Security-Policy`, `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (see Finding 2) | Pass |

## Certificate QR code

| ID | Check | Expected | Actual | Result |
| --- | --- | --- | --- | --- |
| Q1 | The printed certificate shows a QR code that opens `/verify` with its code | Scanning opens the matching result | | Open |
