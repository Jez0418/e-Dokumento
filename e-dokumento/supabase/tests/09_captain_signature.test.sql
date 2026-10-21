-- The captain's e-signature: only the linked captain account sets it, a name or
-- service change clears it, certificates snapshot it, and only people who can see
-- a certificate can read the image.
begin;
select plan(30);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
begin
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.ben', tests.create_resident('ben@example.test', 'Ben', 'Santos')::text, true);
  perform set_config('t.cap', tests.create_staff('cap@example.test', 'captain')::text, true);
  perform set_config('t.cap2', tests.create_staff('cap2@example.test', 'resident')::text, true);
  perform set_config('t.adm', tests.create_staff('adm@example.test', 'admin')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.tre', tests.create_staff('tre@example.test', 'treasurer')::text, true);
  perform set_config('t.res', tests.resident_of(v_ana)::text, true);
  perform set_config('t.path', current_setting('t.cap') || '/aaaaaaaaaaaaaaaaaaaaaaaa.png', true);
  insert into storage.objects (bucket_id, name) values ('signatures', current_setting('t.path'));
  insert into storage.objects (bucket_id, name)
    values ('signatures', current_setting('t.cap2') || '/cccccccccccccccccccccccc.png');
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

-- The captain sets it and is linked to the seat
select tests.act_as(current_setting('t.cap')::uuid);
select public.set_captain_signature(current_setting('t.path'));
select is((select signature_path from public.barangay_officials where position = 'punong_barangay' and is_active),
  current_setting('t.path'), 'The captain can set the signature');
select is((select profile_id::text from public.barangay_officials where position = 'punong_barangay' and is_active),
  current_setting('t.cap'), 'The first save links the captain account to the seat');
select throws_ok(
  $$select public.set_captain_signature('x.png')$$,
  'P0001', 'That signature file is not valid.', 'A malformed path is rejected');
select throws_ok(
  $$select public.set_captain_signature('00000000-0000-0000-0000-000000000000/bbbbbbbbbbbbbbbbbbbbbbbb.png')$$,
  'P0001', 'That signature file is not in your folder.', 'A path in another folder is rejected');

select throws_ok(
  $$select public.set_captain_signature(current_setting('t.cap') || '/dddddddddddddddddddddddd.png')$$,
  'P0001', 'That signature file has not been uploaded.', 'A path with no stored file is rejected');

-- I1: after a handover, the new captain account cannot take over a seat still linked to the old one
reset role;
select set_config('request.jwt.claims', '', true);
savepoint handover;
update public.profiles set status = 'inactive' where id = current_setting('t.cap')::uuid;
update public.profiles set role_id = (select id from public.roles where code = 'captain')
  where id = current_setting('t.cap2')::uuid;
select tests.act_as(current_setting('t.cap2')::uuid);
select throws_ok(
  $$select public.set_captain_signature(current_setting('t.cap2') || '/cccccccccccccccccccccccc.png')$$,
  'P0001', 'This signature belongs to another account. An administrator must reset it first.',
  'A second captain account cannot replace the signature');
reset role;
select set_config('request.jwt.claims', '', true);
rollback to savepoint handover;

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
    values ('Fake Captain', 'punong_barangay', date '2025-07-01', date '2028-06-30', 'evil')$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'An administrator cannot insert a signature_path');
select throws_ok(
  $$update public.profiles set role_id = (select id from public.roles where code = 'captain')
    where id = current_setting('t.adm')::uuid$$,
  'P0001', 'You cannot change your own role.', 'An administrator cannot promote themselves to captain');
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.set_captain_signature(null)$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'The Secretary cannot use the RPC');
select tests.act_as(current_setting('t.tre')::uuid);
select throws_ok(
  $$select public.set_captain_signature(null)$$,
  'P0001', 'Only the Punong Barangay can change the signature.', 'The Treasurer cannot use the RPC');

select tests.act_as(current_setting('t.ana')::uuid);
do $$
begin
  update public.barangay_officials set signature_path = 'evil' where position = 'punong_barangay';
exception when others then
  null;
end $$;
select is((select signature_path from public.barangay_officials where position = 'punong_barangay' and is_active),
  current_setting('t.path'), 'A resident cannot change the signature');
select throws_ok(
  $$insert into storage.objects (bucket_id, name) values ('signatures', current_setting('t.ana') || '/eeeeeeeeeeeeeeeeeeeeeeee.png')$$,
  '42501', null, 'A resident cannot upload into the signatures bucket');

-- I2: renaming the official clears the signature but keeps the link
select tests.act_as(current_setting('t.adm')::uuid);
select lives_ok(
  $$update public.barangay_officials set full_name = 'Renamed Captain' where position = 'punong_barangay'$$,
  'An administrator can still edit other official fields');
select is((select signature_path from public.barangay_officials where position = 'punong_barangay' and is_active),
  null, 'Renaming the official clears the signature');
select is((select profile_id::text from public.barangay_officials where position = 'punong_barangay' and is_active),
  current_setting('t.cap'), 'Renaming keeps the account link');

-- The captain signs again, then two certificates are issued
select tests.act_as(current_setting('t.cap')::uuid);
select public.set_captain_signature(current_setting('t.path'));

reset role;
select set_config('request.jwt.claims', '', true);
do $$
begin
  perform set_config('t.coi', tests.request_in(current_setting('t.res')::uuid, 'COI', 'for_approval')::text, true);
  perform set_config('t.cor', tests.request_in(current_setting('t.res')::uuid, 'COR', 'processing')::text, true);
end $$;

select tests.act_as(current_setting('t.cap')::uuid);
do $$
begin
  perform set_config('t.doc_coi', public.issue_document(current_setting('t.coi')::uuid) ->> 'id', true);
end $$;
select tests.act_as(current_setting('t.sec')::uuid);
do $$
begin
  perform set_config('t.doc_cor', public.issue_document(current_setting('t.cor')::uuid) ->> 'id', true);
end $$;
do $$
begin
  perform set_config('t.code_coi', (select verification_code from public.issued_documents
                                    where id = current_setting('t.doc_coi')::uuid), true);
  perform set_config('t.code_cor', (select verification_code from public.issued_documents
                                    where id = current_setting('t.doc_cor')::uuid), true);
end $$;

select is(public.verify_document(current_setting('t.code_coi')) ->> 'e_signed', 'true',
  'A captain-approval document is e-signed when the signature is on file');
select is(public.verify_document(current_setting('t.code_cor')) ->> 'e_signed', 'false',
  'A document that needs no captain approval is not e-signed');
select is((select signature_path from public.issued_documents where id = current_setting('t.doc_coi')::uuid),
  current_setting('t.path'), 'The certificate snapshots the signature path');

-- I4: the image is readable only by the captain and by people who can see a certificate that uses it
select tests.act_as(current_setting('t.ana')::uuid);
select is((select count(*)::int from storage.objects where bucket_id = 'signatures' and name = current_setting('t.path')),
  1, 'The resident who holds the certificate can read the signature image');
select tests.act_as(current_setting('t.ben')::uuid);
select is((select count(*)::int from storage.objects where bucket_id = 'signatures' and name = current_setting('t.path')),
  0, 'A resident with no such certificate cannot read the signature image');

-- I3: removing the signature later does not un-sign certificates already issued
select tests.act_as(current_setting('t.cap')::uuid);
select public.set_captain_signature(null);
select is(public.verify_document(current_setting('t.code_coi')) ->> 'e_signed', 'true',
  'A certificate stays e-signed after the captain removes the signature');

-- I2: ending and reinstating service wipes the signature and the link
select public.set_captain_signature(current_setting('t.path'));
select tests.act_as(current_setting('t.adm')::uuid);
select lives_ok(
  $$update public.barangay_officials set is_active = false where position = 'punong_barangay' and is_active$$,
  'An administrator can end the captain''s service');
reset role;
select set_config('request.jwt.claims', '', true);
select is((select signature_path from public.barangay_officials where full_name = 'Renamed Captain'),
  null, 'Ending service clears the signature');
select is((select profile_id from public.barangay_officials where full_name = 'Renamed Captain'),
  null, 'Ending service clears the account link');
select tests.act_as(current_setting('t.adm')::uuid);
select lives_ok(
  $$update public.barangay_officials set is_active = true where full_name = 'Renamed Captain'$$,
  'An administrator can reinstate the captain');
select is((select signature_path from public.barangay_officials where full_name = 'Renamed Captain'),
  null, 'A reinstated captain has no signature until they sign again');

-- A revoked certificate is not reported as e-signed
select tests.act_as(current_setting('t.sec')::uuid);
select public.revoke_document(current_setting('t.doc_coi')::uuid, 'Issued to the wrong resident');
select is(public.verify_document(current_setting('t.code_coi')) ->> 'e_signed', 'false',
  'A revoked document is not reported as e-signed');

reset role;
select * from finish();
rollback;
