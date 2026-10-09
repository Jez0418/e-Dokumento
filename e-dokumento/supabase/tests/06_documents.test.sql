-- Issuing, verifying and revoking documents, and report totals.
begin;
select plan(14);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
-- No Punong Barangay exists yet.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
begin
  perform tests.create_resident('ben@example.test', 'Ben', 'Santos', false);
  perform set_config('t.req', tests.request_in(tests.resident_of(v_ana), 'COR', 'processing')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.purok', (select id from public.puroks where name = 'Purok 1')::text, true);
end $$;

select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.issue_document(current_setting('t.req')::uuid)$$,
  'P0001', 'Add the active Punong Barangay under Officials before issuing documents.',
  'Documents cannot be issued without an active Punong Barangay');

reset role;
select set_config('request.jwt.claims', '', true);
select tests.add_captain();

-- The Secretary issues a Certificate of Residency directly
select tests.act_as(current_setting('t.sec')::uuid);
do $$
begin
  perform set_config('t.doc', public.issue_document(current_setting('t.req')::uuid) ->> 'id', true);
  perform set_config('t.code', (select verification_code from public.issued_documents
                                where id = current_setting('t.doc')::uuid), true);
end $$;

select is(public.verify_document(current_setting('t.code')) ->> 'found', 'true', 'A new document is found');
select is(public.verify_document(current_setting('t.code')) ->> 'status', 'valid', 'A new document is valid');
select is(public.verify_document(current_setting('t.code')) ->> 'expired', 'false', 'A new document is not expired');
select is(public.verify_document(lower(current_setting('t.code'))), public.verify_document(current_setting('t.code')),
  'The code is not case-sensitive');

reset role;
select set_config('request.jwt.claims', '', true);
update public.issued_documents set valid_until = current_date - 1 where id = current_setting('t.doc')::uuid;
select is(public.verify_document(current_setting('t.code')) ->> 'expired', 'true', 'A document past its validity is expired');

-- Revoking needs a reason and keeps the row
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.revoke_document(current_setting('t.doc')::uuid, 'short')$$,
  'P0001', 'Give a reason of 10 to 500 characters.', 'A revocation needs a reason of 10 to 500 characters');
select public.revoke_document(current_setting('t.doc')::uuid, 'Issued to the wrong resident');
select is((select status from public.issued_documents where id = current_setting('t.doc')::uuid),
  'revoked', 'A revoked document stays in the database');
select is((select revoked_reason from public.issued_documents where id = current_setting('t.doc')::uuid),
  'Issued to the wrong resident', 'The revocation reason is stored');
select is(public.verify_document(current_setting('t.code')) ->> 'status', 'revoked', '/verify reports a revoked document');

select is(public.verify_document('ZZZZZZZZZZ') ->> 'found', 'false', 'A code that is not hex is not found');
select is(public.verify_document('0000000000') ->> 'found', 'false', 'An unknown code is not found');

-- Report totals match a direct count, as the Secretary
select is(
  (select total from public.report_residents_by_purok() where purok = 'Purok 1'),
  (select count(*) from public.residents where purok_id = current_setting('t.purok')::smallint and status = 'active'),
  'Residents-by-purok total matches a direct count');

-- Anyone can check a certificate
reset role;
select tests.act_as_anon();
select is(public.verify_document(current_setting('t.code')) ->> 'found', 'true', 'Anonymous callers can verify a certificate');

reset role;
select * from finish();
rollback;
