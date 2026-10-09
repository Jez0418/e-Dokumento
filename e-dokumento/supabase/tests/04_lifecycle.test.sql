-- The request lifecycle (README step 9), from a Barangay Clearance that ana submitted online.
begin;
select plan(23);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
-- One open request per resident per type, so the side cases use separate residents.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
  v_ben uuid := tests.create_resident('ben@example.test', 'Ben', 'Santos');
  v_cy  uuid := tests.create_resident('cy@example.test', 'Cy', 'Lim');
begin
  perform tests.add_captain();
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.ana_res', tests.resident_of(v_ana)::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.treasurer', tests.create_staff('treasurer@example.test', 'treasurer')::text, true);
  perform set_config('t.captain', tests.create_staff('captain@example.test', 'captain')::text, true);
  perform set_config('t.cor_processing', tests.request_in(tests.resident_of(v_cy), 'COR', 'processing')::text, true);
  perform set_config('t.cor_review', tests.request_in(tests.resident_of(v_ben), 'COR', 'under_review')::text, true);
  perform set_config('t.cor_pending', tests.request_in(tests.resident_of(v_ana), 'COR', 'pending')::text, true);
end $$;

-- Ana submits the clearance online
select tests.act_as(current_setting('t.ana')::uuid);
do $$
begin
  perform set_config('t.bc', public.submit_request(
    current_setting('t.ana_res')::uuid, tests.type_id('BC'), tests.purpose_id(), null, 1, '{}'::jsonb,
    tests.id_attachments(current_setting('t.ana_res')::uuid)) ->> 'id', true);
end $$;
reset role;
select set_config('request.jwt.claims', '', true);
select set_config('t.att', (select id from public.request_attachments
                            where request_id = current_setting('t.bc')::uuid)::text, true);

-- Secretary: review, accept the file, send for payment
select tests.act_as(current_setting('t.sec')::uuid);
select is(public.transition_request(current_setting('t.bc')::uuid, 'under_review') ->> 'status',
  'under_review', 'Secretary starts the review');
select throws_ok(
  $$select public.transition_request(current_setting('t.bc')::uuid, 'for_payment')$$,
  'P0001', 'Accept every required file first (1 still pending or rejected).',
  'Payment waits until the required file is accepted');
select public.review_attachment(current_setting('t.att')::uuid, 'accepted');
select is(public.transition_request(current_setting('t.bc')::uuid, 'for_payment') ->> 'status',
  'for_payment', 'Secretary sends it for payment after accepting the file');

-- Treasurer: record the payment
select tests.act_as(current_setting('t.treasurer')::uuid);
select public.record_payment(current_setting('t.bc')::uuid, 'OR-1001', 50, 'cash');
select is((select status from public.document_requests where id = current_setting('t.bc')::uuid),
  'processing', 'Recording the payment moves it to processing');

-- Secretary: cannot issue a captain-approval document, sends it for approval
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.issue_document(current_setting('t.bc')::uuid)$$,
  'P0001', 'Send this request for the captain''s approval first.',
  'The Secretary cannot issue a document that needs the captain''s approval');
select is(public.transition_request(current_setting('t.bc')::uuid, 'for_approval') ->> 'status',
  'for_approval', 'Secretary sends it for the captain''s approval');

-- Punong Barangay: approve
select tests.act_as(current_setting('t.captain')::uuid);
select public.issue_document(current_setting('t.bc')::uuid);
select is((select status from public.document_requests where id = current_setting('t.bc')::uuid),
  'ready_for_release', 'The captain''s approval makes it ready for release');
select is((select count(*)::int from public.issued_documents where request_id = current_setting('t.bc')::uuid),
  1, 'Approval issues one document');
select matches((select verification_code from public.issued_documents where request_id = current_setting('t.bc')::uuid),
  '^[0-9A-F]{10}$', 'The verification code is 10 hex characters');

-- Release
select tests.act_as(current_setting('t.ana')::uuid);
select throws_ok(
  $$select public.transition_request(current_setting('t.bc')::uuid, 'released', null, 'Ana Reyes')$$,
  'P0001', 'That status change is not allowed for your role at this stage.',
  'A resident cannot release their own request');

select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.transition_request(current_setting('t.cor_processing')::uuid, 'ready_for_release')$$,
  'P0001', 'Use Issue document to complete this step.',
  'Ready for release is reached only through Issue document');
select throws_ok(
  $$select public.transition_request(current_setting('t.bc')::uuid, 'released', null, 'A')$$,
  'P0001', 'Enter the name of the person who claimed the document.',
  'Release needs the claimant''s name');
select is(public.transition_request(current_setting('t.bc')::uuid, 'released', null, 'Ana Reyes') ->> 'status',
  'released', 'Secretary releases it');
select is((select released_to from public.document_requests where id = current_setting('t.bc')::uuid),
  'Ana Reyes', 'The claimant is recorded');

-- The whole run, as postgres
reset role;
select set_config('request.jwt.claims', '', true);
select is((select count(*)::int from public.request_status_history where request_id = current_setting('t.bc')::uuid),
  7, 'Every status of the run has a history row');
select is((select count(*)::int from public.audit_logs
           where action = 'status_change' and entity_id = current_setting('t.bc')),
  4, 'Each successful transition_request call is audited once');
select is((select count(*)::int from public.audit_logs
           where action = 'approve' and entity_id = current_setting('t.bc')),
  1, 'The captain''s approval is audited');
select ok((select count(*) from public.audit_logs
           where action = 'payment_post'
             and details ->> 'control_no' = (select control_no from public.document_requests
                                             where id = current_setting('t.bc')::uuid)) >= 1,
  'The payment is audited');

-- A Certificate of Residency does not need the captain's approval
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.transition_request(current_setting('t.cor_processing')::uuid, 'for_approval')$$,
  'P0001', 'This document does not need the captain''s approval. Issue it directly.',
  'A no-approval document cannot be sent for approval');

-- Rejection needs a reason, and the row stays
select throws_ok(
  $$select public.transition_request(current_setting('t.cor_review')::uuid, 'rejected', 'short')$$,
  'P0001', 'Give a reason of 10 to 500 characters.',
  'A rejection needs a reason of 10 to 500 characters');
select public.transition_request(current_setting('t.cor_review')::uuid, 'rejected', 'Proof of residency is unreadable');
select is((select status from public.document_requests where id = current_setting('t.cor_review')::uuid),
  'rejected', 'A rejected request stays in the database');
select is((select rejection_reason from public.document_requests where id = current_setting('t.cor_review')::uuid),
  'Proof of residency is unreadable', 'The rejection reason is stored');

-- A resident cancels their own pending request, and the row stays
select tests.act_as(current_setting('t.ana')::uuid);
select public.transition_request(current_setting('t.cor_pending')::uuid, 'cancelled');
select is((select status from public.document_requests where id = current_setting('t.cor_pending')::uuid),
  'cancelled', 'A cancelled request stays in the database');

reset role;
select * from finish();
rollback;
