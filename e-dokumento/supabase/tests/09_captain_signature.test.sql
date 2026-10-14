-- The captain's e-signature: only the captain may set it, and verify reports it.
begin;
select plan(13);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
begin
  perform set_config('t.cap', tests.create_staff('cap@example.test', 'captain')::text, true);
  perform set_config('t.adm', tests.create_staff('adm@example.test', 'admin')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.tre', tests.create_staff('tre@example.test', 'treasurer')::text, true);
  perform set_config('t.res', tests.resident_of(v_ana)::text, true);
  perform set_config('t.path', current_setting('t.cap') || '/aaaaaaaaaaaaaaaaaaaaaaaa.png', true);
end $$;

-- No Punong Barangay yet
select tests.act_as(current_setting('t.cap')::uuid);
select throws_ok(
  $$select public.set_captain_signature(current_setting('t.path'))$$,
  'P0001', 'Add the active Punong Barangay under Officials first.',
  'The signature needs an active Punong Barangay');

reset role;
select set_config('request.jwt.claims', '', true);
select tests.add_captain();

-- The captain sets and removes it
select tests.act_as(current_setting('t.cap')::uuid);
select public.set_captain_signature(current_setting('t.path'));
select is((select signature_path from public.barangay_officials where position = 'punong_barangay' and is_active),
  current_setting('t.path'), 'The captain can set the signature');
select throws_ok(
  $$select public.set_captain_signature('x.png')$$,
  'P0001', 'That signature file is not valid.', 'A malformed path is rejected');
select throws_ok(
  $$select public.set_captain_signature('00000000-0000-0000-0000-000000000000/bbbbbbbbbbbbbbbbbbbbbbbb.png')$$,
  'P0001', 'That signature file is not in your folder.', 'A path in another folder is rejected');

-- Everyone else is rejected, through the RPC and by direct writes
select tests.act_as(current_setting('t.adm')::uuid);
select throws_ok(
  $$select public.set_captain_signature(current_setting('t.path'))$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'An administrator cannot use the RPC');
select throws_ok(
  $$update public.barangay_officials set signature_path = 'evil' where position = 'punong_barangay'$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'An administrator cannot update signature_path directly');
select throws_ok(
  $$insert into public.barangay_officials (full_name, position, term_start, term_end, signature_path)
    values ('Fake Kagawad', 'kagawad', date '2025-07-01', date '2028-06-30', 'evil')$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'An administrator cannot insert a signature_path');
select lives_ok(
  $$update public.barangay_officials set full_name = 'Renamed Captain' where position = 'punong_barangay'$$,
  'An administrator can still edit other official fields');
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.set_captain_signature(null)$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'The Secretary cannot use the RPC');
select tests.act_as(current_setting('t.tre')::uuid);
select throws_ok(
  $$select public.set_captain_signature(null)$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'The Treasurer cannot use the RPC');

-- verify_document reports e_signed
reset role;
select set_config('request.jwt.claims', '', true);
do $$
declare
  v_coi uuid := tests.request_in(current_setting('t.res')::uuid, 'COI', 'for_approval');
  v_cor uuid := tests.request_in(current_setting('t.res')::uuid, 'COR', 'processing');
begin
  perform set_config('t.coi', v_coi::text, true);
  perform set_config('t.cor', v_cor::text, true);
end $$;

select tests.act_as(current_setting('t.cap')::uuid);
do $$
begin
  perform set_config('t.code_coi', (select verification_code from public.issued_documents
    where id = (public.issue_document(current_setting('t.coi')::uuid) ->> 'id')::uuid), true);
end $$;
select tests.act_as(current_setting('t.sec')::uuid);
do $$
begin
  perform set_config('t.code_cor', (select verification_code from public.issued_documents
    where id = (public.issue_document(current_setting('t.cor')::uuid) ->> 'id')::uuid), true);
end $$;

select is(public.verify_document(current_setting('t.code_coi')) ->> 'e_signed', 'true',
  'A captain-approval document is e-signed when the signature is on file');
select is(public.verify_document(current_setting('t.code_cor')) ->> 'e_signed', 'false',
  'A document that needs no captain approval is not e-signed');

select tests.act_as(current_setting('t.cap')::uuid);
select public.set_captain_signature(null);
select is(public.verify_document(current_setting('t.code_coi')) ->> 'e_signed', 'false',
  'Without a signature on file the document is not e-signed');

select * from finish();
rollback;
