# Testing checklist

Run every item on the **production Vercel URL**, not only locally. Create one account per role first (see README, step 8).

## Authentication and authorization
- [ ] Valid login reaches the dashboard for each of the five roles
- [ ] Wrong password and unknown email both show "Incorrect email or password."
- [ ] A deactivated user (Users page) cannot sign in
- [ ] Session survives a page refresh and a new deployment; Sign out ends it
- [ ] After an hour the session refreshes silently (access token expiry)
- [ ] Opening `/residents` while signed out redirects to Sign in — auto: `tests/Http/FrontControllerTest.php`
- [ ] Opening `/users` as a Secretary shows the Access Denied page — auto (route map): `tests/Unit/RoutesTest.php`
- [ ] With a resident's access token, `GET /rest/v1/residents` returns only their own row (RLS) — auto: `supabase/tests/01_rls.test.sql`
- [ ] Submitting a form copied to another site fails with "This form expired" (CSRF) — auto: `tests/Http/FrontControllerTest.php`

## Create
- [ ] A verified resident submits a request and receives a control number — auto: `supabase/tests/02_submit_request.test.sql`
- [ ] An unverified resident sees "Verify your residency first" — auto: `supabase/tests/02_submit_request.test.sql`
- [ ] A second open request for the same document is refused — auto: `supabase/tests/02_submit_request.test.sql`
- [ ] A missing required file, a 3 MB file, or a .docx renamed to .pdf is refused — auto: `tests/Unit/UploadTest.php`
- [ ] Adding a resident with the same name and birth date as an existing one is refused — auto: `supabase/tests/03_integrity.test.sql`
- [ ] A second First Time Jobseeker request after one was released is refused — auto: `supabase/tests/02_submit_request.test.sql`

## Read
- [ ] Requests and Residents search, filter, sort and paginate
- [ ] Request details show the full timeline with who changed each status
- [ ] `/verify` shows a valid certificate, an expired one, and rejects a revoked or unknown code

## Update
- [ ] Each transition in the lifecycle works for its role and adds a history row
- [ ] A resident cannot move their own request to Released (call `transition_request` directly to confirm the database refuses)
- [ ] Payment with a wrong amount, or a reused OR number, is refused
- [ ] Editing a resident changes the row in Supabase Table Editor

## Delete, deactivate, cancel, void
- [ ] Every destructive button asks for confirmation; reject, void and revoke ask for a reason
- [ ] Cancelled, voided and revoked rows remain in the database with their reason
- [ ] A requirement linked to a document type cannot be deleted — auto: `supabase/tests/03_integrity.test.sql`
- [ ] A deactivated purok disappears from forms but stays on existing residents

## Relationships
- [ ] Inserting a request with a random resident_id in the SQL editor fails on the foreign key — auto: `supabase/tests/03_integrity.test.sql`
- [ ] A request accepts only one posted payment and one issued document — auto: `supabase/tests/03_integrity.test.sql`
- [ ] Deleting a request that has a payment fails (ON DELETE RESTRICT) — auto: `supabase/tests/03_integrity.test.sql`

## Dashboard, reports, audit
- [ ] Filing, paying and releasing a request changes the pipeline counts and the chart
- [ ] Report totals match `select count(*)` for the same filters in the SQL editor
- [ ] CSV export opens in Excel with ñ and ₱ shown correctly
- [ ] Sign-ins, edits, status changes, payments and voids appear in the Audit log

## Deployment
- [ ] No secret key appears in page source or browser network responses — auto: `tests/Http/FrontControllerTest.php`
- [ ] Confirmation and password reset emails link to the production URL
