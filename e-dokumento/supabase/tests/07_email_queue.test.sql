-- Status emails: the trigger queues one email when a request needs the resident to act.
begin;
select plan(22);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
-- Each case uses its own resident so it cannot collide with the one-open-request-per-type index.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
  v_ben uuid := tests.create_resident('ben@example.test', 'Ben', 'Santos');
  v_cy  uuid := tests.create_resident('cy@example.test', 'Cy', 'Lim');
  v_dee uuid := tests.create_resident('dee@example.test', 'Dee', 'Cruz');
  v_eve uuid := tests.create_resident('eve@example.test', 'Eve', 'Tan');
  v_fay uuid := tests.create_resident('fay@example.test', 'Fay', 'Uy');
  v_r1 uuid;
  v_r2 uuid;
  v_r4 uuid;
  v_r5 uuid;
  v_r6 uuid;
begin
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.treasurer', tests.create_staff('treasurer@example.test', 'treasurer')::text, true);
  perform set_config('t.admin', tests.create_staff('admin@example.test', 'admin')::text, true);
  update public.system_settings set value = 'true' where key = 'email_notifications_enabled';

  -- For payment
  v_r1 := tests.request_in(tests.resident_of(v_ana), 'BC', 'under_review');
  update public.document_requests set status = 'for_payment' where id = v_r1;
  perform set_config('t.r1', v_r1::text, true);

  -- Ready for release
  v_r2 := tests.request_in(tests.resident_of(v_ben), 'COR', 'processing');
  update public.document_requests set status = 'ready_for_release' where id = v_r2;
  perform set_config('t.r2', v_r2::text, true);

  -- Rejected: done below through transition_request
  perform set_config('t.r3', tests.request_in(tests.resident_of(v_cy), 'COR', 'under_review')::text, true);

  -- No email for other statuses
  v_r4 := tests.request_in(tests.resident_of(v_dee), 'COR', 'pending');
  update public.document_requests set status = 'under_review' where id = v_r4;
  update public.document_requests set status = 'processing' where id = v_r4;
  update public.document_requests set status = 'released', released_at = now(), released_to = 'Dee' where id = v_r4;
  perform set_config('t.r4', v_r4::text, true);

  -- No email when the status does not change
  update public.document_requests set purpose_details = 'For a job abroad' where id = v_r1;

  -- No email when the resident has no email address
  update public.residents set email = null where id = tests.resident_of(v_eve);
  v_r5 := tests.request_in(tests.resident_of(v_eve), 'BC', 'under_review');
  update public.document_requests set status = 'for_payment' where id = v_r5;
  perform set_config('t.r5', v_r5::text, true);

  -- No email when the switch is off
  update public.system_settings set value = 'false' where key = 'email_notifications_enabled';
  v_r6 := tests.request_in(tests.resident_of(v_fay), 'BC', 'under_review');
  update public.document_requests set status = 'for_payment' where id = v_r6;
  perform set_config('t.r6', v_r6::text, true);
  update public.system_settings set value = 'true' where key = 'email_notifications_enabled';
end $$;

select tests.act_as(current_setting('t.sec')::uuid);
select public.transition_request(current_setting('t.r3')::uuid, 'rejected', 'Missing proof of residency');
reset role;
select set_config('request.jwt.claims', '', true);

select has_table('public', 'email_outbox', 'The email outbox table exists');
select ok((select relrowsecurity from pg_class where oid = 'public.email_outbox'::regclass), 'RLS is on for the outbox');

-- For payment
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  1, 'For payment queues one email');
select is((select kind from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  'for_payment', 'The email is a for_payment email');
select is((select to_email from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  'ana@example.test', 'It goes to the resident''s email address');
select is((select status from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  'queued', 'It starts queued');
select is((select attempts::int from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  0, 'It has no attempts yet');
select matches((select subject from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  '^REQ-[0-9]{4}-[0-9]{6}: Pay PHP 50\.00 for your Barangay Clearance$', 'The subject names the control number, fee and document');

-- Ready for release
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r2')::uuid),
  1, 'Ready for release queues one email');
select is((select kind from public.email_outbox where request_id = current_setting('t.r2')::uuid),
  'ready_for_release', 'The email is a ready_for_release email');
select ok((select body like '%bring a valid ID%' from public.email_outbox where request_id = current_setting('t.r2')::uuid),
  'The pickup email reminds the resident to bring a valid ID');

-- Rejected
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r3')::uuid),
  1, 'Rejection through transition_request queues one email');
select is((select kind from public.email_outbox where request_id = current_setting('t.r3')::uuid),
  'rejected', 'The email is a rejected email');
select ok((select body like '%Missing proof of residency%' from public.email_outbox where request_id = current_setting('t.r3')::uuid),
  'The rejection email gives the reason');

-- No email
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r4')::uuid),
  0, 'Other statuses queue no email');
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r1')::uuid),
  1, 'An update that keeps the status queues no email');
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r5')::uuid),
  0, 'A resident with no email address gets no email');
select is((select count(*)::int from public.email_outbox where request_id = current_setting('t.r6')::uuid),
  0, 'No email is queued while the switch is off');

-- Who can read the outbox
select tests.act_as(current_setting('t.ana')::uuid);
select is((select count(*)::int from public.email_outbox), 0, 'A resident cannot read the outbox');
select tests.act_as(current_setting('t.treasurer')::uuid);
select is((select count(*)::int from public.email_outbox), 0, 'The treasurer cannot read the outbox');
select tests.act_as(current_setting('t.admin')::uuid);
select is((select count(*)::int from public.email_outbox), 3, 'The Administrator reads every outbox row');

-- Nobody else can write it
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$insert into public.email_outbox (request_id, to_email, kind, subject, body)
    values (current_setting('t.r1')::uuid, 'x@example.test', 'for_payment', 'Hi', 'Body')$$,
  '42501', null, 'Staff cannot write to the outbox directly');

reset role;
select * from finish();
rollback;
