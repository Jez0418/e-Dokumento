-- Status emails: claiming, finishing and retrying queued emails.
begin;
select plan(19);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
  v_bc  uuid;
  v_coi uuid;
begin
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.treasurer', tests.create_staff('treasurer@example.test', 'treasurer')::text, true);
  perform set_config('t.admin', tests.create_staff('admin@example.test', 'admin')::text, true);
  update public.system_settings set value = 'true' where key = 'email_notifications_enabled';

  v_bc := tests.request_in(tests.resident_of(v_ana), 'BC', 'under_review');
  update public.document_requests set status = 'for_payment' where id = v_bc;
  v_coi := tests.request_in(tests.resident_of(v_ana), 'COI', 'processing');
  update public.document_requests set status = 'ready_for_release' where id = v_coi;

  -- now() is the same for the whole transaction, so age the BC email to make it the oldest.
  update public.email_outbox set created_at = now() - interval '1 minute' where request_id = v_bc;
  perform set_config('t.id1', (select id from public.email_outbox where request_id = v_bc)::text, true);
  perform set_config('t.id2', (select id from public.email_outbox where request_id = v_coi)::text, true);
end $$;

-- Claims hand out each queued row once, oldest first
select tests.act_as(current_setting('t.sec')::uuid);
select is((select array_agg(id) from public.claim_outbox_emails(1)), array[current_setting('t.id1')::uuid],
  'The first claim returns the oldest email');
select is((select array_agg(id) from public.claim_outbox_emails(1)), array[current_setting('t.id2')::uuid],
  'The second claim returns the other email');
select is((select count(*)::int from public.claim_outbox_emails(1)), 0, 'A third claim returns nothing');

reset role;
select set_config('request.jwt.claims', '', true);
select ok((select status = 'sending' and claimed_at is not null from public.email_outbox where id = current_setting('t.id1')::uuid),
  'A claimed email is marked sending with its claim time');

-- A sending row older than 10 minutes is claimed again
update public.email_outbox set claimed_at = now() - interval '11 minutes' where id = current_setting('t.id1')::uuid;
select tests.act_as(current_setting('t.sec')::uuid);
select is((select array_agg(id) from public.claim_outbox_emails(5)), array[current_setting('t.id1')::uuid],
  'A stale sending email is reclaimed');

-- Finishing
select public.finish_outbox_email(current_setting('t.id1')::uuid, true);
select public.finish_outbox_email(current_setting('t.id2')::uuid, false, repeat('x', 600));
reset role;
select set_config('request.jwt.claims', '', true);
select ok((select status = 'sent' and sent_at is not null from public.email_outbox where id = current_setting('t.id1')::uuid),
  'A successful send marks the email sent');
select is((select status from public.email_outbox where id = current_setting('t.id2')::uuid), 'queued',
  'A failed send goes back to the queue');
select is((select attempts::int from public.email_outbox where id = current_setting('t.id2')::uuid), 1,
  'A failed send counts one attempt');
select is((select char_length(last_error) from public.email_outbox where id = current_setting('t.id2')::uuid), 500,
  'The error is cut to 500 characters');

-- Four more failures make five attempts
select tests.act_as(current_setting('t.sec')::uuid);
do $$
begin
  for i in 1..4 loop
    perform * from public.claim_outbox_emails(5);
    perform public.finish_outbox_email(current_setting('t.id2')::uuid, false, 'Gmail refused the login');
  end loop;
end $$;
select is((select count(*)::int from public.claim_outbox_emails(5)), 0, 'A failed email is not claimed again');
reset role;
select set_config('request.jwt.claims', '', true);
select is((select attempts::int from public.email_outbox where id = current_setting('t.id2')::uuid), 5,
  'Five failures are counted');
select is((select status from public.email_outbox where id = current_setting('t.id2')::uuid), 'failed',
  'The fifth failure marks the email failed');

-- Who may claim and retry
select tests.act_as(current_setting('t.ana')::uuid);
select throws_ok($$select * from public.claim_outbox_emails(1)$$,
  'P0001', 'Only barangay staff can send email notifications.', 'A resident cannot claim emails');

select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok($$select public.retry_outbox_email(current_setting('t.id2')::uuid)$$,
  'P0001', 'Only the Administrator can retry emails.', 'The Secretary cannot retry emails');

select tests.act_as(current_setting('t.admin')::uuid);
select public.retry_outbox_email(current_setting('t.id2')::uuid);
select is((select status from public.email_outbox where id = current_setting('t.id2')::uuid), 'queued',
  'A retried email is queued again');
select is((select attempts::int from public.email_outbox where id = current_setting('t.id2')::uuid), 0,
  'A retried email starts its attempts over');
select is((select count(*)::int from public.audit_logs where action = 'email_retry' and entity_id = current_setting('t.id2')),
  1, 'The retry is audited');
select throws_ok($$select public.retry_outbox_email(current_setting('t.id1')::uuid)$$,
  'P0001', 'Only failed emails can be retried.', 'A sent email cannot be retried');

reset role;
select tests.act_as_anon();
select throws_ok($$select * from public.claim_outbox_emails(1)$$,
  '42501', null, 'Anonymous callers cannot claim emails');

reset role;
select * from finish();
rollback;
