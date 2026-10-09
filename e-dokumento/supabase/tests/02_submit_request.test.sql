-- submit_request: the rules a request must pass before it is created.
begin;
select plan(13);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
  v_ben uuid := tests.create_resident('ben@example.test', 'Ben', 'Santos', false);
  v_cy  uuid := tests.create_resident('cy@example.test', 'Cy', 'Lim');
  v_dee uuid := tests.create_resident('dee@example.test', 'Dee', 'Cruz');
begin
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.ana_res', tests.resident_of(v_ana)::text, true);
  perform set_config('t.ben_res', tests.resident_of(v_ben)::text, true);
  perform set_config('t.ben', v_ben::text, true);
  perform set_config('t.cy', v_cy::text, true);
  perform set_config('t.cy_res', tests.resident_of(v_cy)::text, true);
  perform set_config('t.dee', v_dee::text, true);
  perform set_config('t.dee_res', tests.resident_of(v_dee)::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform tests.request_in(tests.resident_of(v_dee), 'FTJ', 'released');
end $$;

-- A verified resident submits a Barangay Clearance online
select tests.act_as(current_setting('t.ana')::uuid);
do $$
begin
  perform set_config('t.bc', public.submit_request(
    current_setting('t.ana_res')::uuid, tests.type_id('BC'), tests.purpose_id(), null, 1, '{}'::jsonb,
    tests.id_attachments(current_setting('t.ana_res')::uuid)) ->> 'id', true);
end $$;
reset role;
select set_config('request.jwt.claims', '', true);

select matches(
  (select control_no from public.document_requests where id = current_setting('t.bc')::uuid),
  '^REQ-[0-9]{4}-[0-9]{6}$', 'The control number looks like REQ-YYYY-NNNNNN');
select is(
  (select status from public.document_requests where id = current_setting('t.bc')::uuid),
  'pending', 'A new request starts as pending');
select is(
  (select channel from public.document_requests where id = current_setting('t.bc')::uuid),
  'online', 'A resident''s request is an online request');
select is(
  (select fee_amount from public.document_requests where id = current_setting('t.bc')::uuid),
  50.00::numeric, 'The Barangay Clearance fee is 50.00');
select is(
  (select count(*)::int from public.request_status_history
    where request_id = current_setting('t.bc')::uuid and to_status = 'pending'),
  1, 'One history row records the pending status');

-- Rules a resident's request must pass
select tests.act_as(current_setting('t.ana')::uuid);
select throws_ok(
  $$select public.submit_request(current_setting('t.ana_res')::uuid, tests.type_id('BC'), tests.purpose_id(), null, 1,
      '{}'::jsonb, tests.id_attachments(current_setting('t.ana_res')::uuid))$$,
  'P0001', 'There is already an open request for Barangay Clearance. Track it in Requests.',
  'Only one open request per document type');

select tests.act_as(current_setting('t.ben')::uuid);
select throws_ok(
  $$select public.submit_request(current_setting('t.ben_res')::uuid, tests.type_id('COR'), tests.purpose_id())$$,
  'P0001', 'Your residency must be verified before you can request documents online.',
  'An unverified resident cannot request online');

select tests.act_as(current_setting('t.cy')::uuid);
select throws_ok(
  $$select public.submit_request(current_setting('t.cy_res')::uuid, tests.type_id('BC'), tests.purpose_id(), null, 1,
      '{}'::jsonb, '[]'::jsonb)$$,
  'P0001', 'Upload the required files: Valid government-issued ID.',
  'Required files must be uploaded');

select tests.act_as(current_setting('t.ana')::uuid);
select throws_ok(
  $$select public.submit_request(current_setting('t.ben_res')::uuid, tests.type_id('COR'), tests.purpose_id())$$,
  'P0001', 'You can only request documents for yourself.',
  'A resident cannot request for someone else');
select throws_ok(
  $$select public.submit_request(current_setting('t.ana_res')::uuid, tests.type_id('COR'), tests.purpose_id(), null, 4)$$,
  'P0001', 'You can request 1 to 3 copies of this document.',
  'Copies are limited to the document''s maximum');

select tests.act_as(current_setting('t.dee')::uuid);
select throws_ok(
  $$select public.submit_request(current_setting('t.dee_res')::uuid, tests.type_id('FTJ'), tests.purpose_id(), null, 1,
      '{}'::jsonb, tests.id_attachments(current_setting('t.dee_res')::uuid))$$,
  'P0001', 'First Time Jobseeker Certification can only be issued once per resident.',
  'First Time Jobseeker is once per resident');

-- The Secretary files a walk-in request for an unverified resident, with no files
select tests.act_as(current_setting('t.sec')::uuid);
do $$
begin
  perform set_config('t.walkin', public.submit_request(
    current_setting('t.ben_res')::uuid, tests.type_id('COR'), tests.purpose_id()) ->> 'id', true);
end $$;
reset role;
select set_config('request.jwt.claims', '', true);

select is(
  (select channel from public.document_requests where id = current_setting('t.walkin')::uuid),
  'walk_in', 'The Secretary''s request is a walk-in request');
select is(
  (select verification_status from public.residents where id = current_setting('t.ben_res')::uuid),
  'verified', 'Filing a walk-in request verifies the resident in person');

select * from finish();
rollback;
