-- Relational integrity: unique keys, foreign keys and column guards.
begin;
select plan(8);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
do $$
declare
  v_ana     uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
  v_ben     uuid := tests.create_resident('ben@example.test', 'Ben', 'Santos');
  v_treas   uuid := tests.create_staff('treasurer@example.test', 'treasurer');
  v_ana_res uuid := tests.resident_of(v_ana);
  v_paid    uuid := tests.request_in(v_ana_res, 'COR', 'for_payment');
  v_issued  uuid := tests.request_in(v_ana_res, 'BC', 'processing');
begin
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.purok', (select id from public.puroks where name = 'Purok 1')::text, true);
  perform set_config('t.paid', v_paid::text, true);
  perform set_config('t.issued', v_issued::text, true);
  perform set_config('t.pending', tests.request_in(tests.resident_of(v_ben), 'COR', 'pending')::text, true);
  perform set_config('t.captain', tests.add_captain()::text, true);

  insert into public.payments (request_id, or_number, amount, method, received_by)
  values (v_paid, 'OR-9001', 30.00, 'cash', v_treas);
  perform set_config('t.treasurer', v_treas::text, true);

  insert into public.issued_documents (request_id, document_no, verification_code, signatory_id)
  values (v_issued, 'TEST-DOC-1', 'ABCDEF0123', current_setting('t.captain')::smallint);
end $$;

-- One person per name and birth date
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$insert into public.residents (purok_id, first_name, last_name, birth_date, sex, civil_status,
                                  street_address, resident_since, created_by)
    values (current_setting('t.purok')::smallint, 'Ana', 'Reyes', date '1990-01-01', 'female', 'single',
            '45 Mabini St', date '2015-01-01', current_setting('t.sec')::uuid)$$,
  '23505', null, 'A second resident with the same name and birth date is refused');
reset role;
select set_config('request.jwt.claims', '', true);

select throws_ok(
  $$insert into public.document_requests (resident_id, document_type_id, purpose_id, channel, requested_by)
    values (gen_random_uuid(), tests.type_id('COR'), tests.purpose_id(), 'walk_in', current_setting('t.ana')::uuid)$$,
  '23503', null, 'A request must belong to an existing resident');

select throws_ok(
  $$delete from public.requirements where name = 'Valid government-issued ID'$$,
  '23503', null, 'A requirement linked to a document type cannot be deleted');

select throws_ok(
  $$insert into public.payments (request_id, or_number, amount, method, received_by)
    values (current_setting('t.paid')::uuid, 'OR-9002', 30.00, 'cash', current_setting('t.treasurer')::uuid)$$,
  '23505', null, 'A request has at most one posted payment');
select throws_ok(
  $$delete from public.document_requests where id = current_setting('t.paid')::uuid$$,
  '23503', null, 'A request with a payment cannot be deleted');

select throws_ok(
  $$insert into public.issued_documents (request_id, document_no, verification_code, signatory_id)
    values (current_setting('t.issued')::uuid, 'TEST-DOC-2', 'ABCDEF0124', current_setting('t.captain')::smallint)$$,
  '23505', null, 'A request has at most one issued document');

-- A resident cannot promote themselves
select tests.act_as(current_setting('t.ana')::uuid);
select throws_ok(
  $$update public.profiles set role_id = 1 where id = current_setting('t.ana')::uuid$$,
  'P0001', 'You cannot change role, status or email from your profile.',
  'A resident cannot change their own role');
reset role;
select set_config('request.jwt.claims', '', true);

-- A deactivated account loses access at once
update public.profiles set status = 'inactive' where id = current_setting('t.sec')::uuid;
select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.transition_request(current_setting('t.pending')::uuid, 'under_review')$$,
  'P0001', 'Sign in to continue.',
  'An inactive staff account cannot change a request');
reset role;

select * from finish();
rollback;
