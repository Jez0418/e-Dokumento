-- record_payment and void_payment, on Barangay Clearances (fee 50.00) waiting for payment.
begin;
select plan(11);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
-- One open request per resident per type, so each request belongs to a different resident.
-- (resident_of is stable, so it runs as its own statement after create_resident.)
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
  v_ben uuid := tests.create_resident('ben@example.test', 'Ben', 'Santos');
  v_cy  uuid := tests.create_resident('cy@example.test', 'Cy', 'Lim');
begin
  perform set_config('t.req', tests.request_in(tests.resident_of(v_ana), 'BC', 'for_payment')::text, true);
  perform set_config('t.req2', tests.request_in(tests.resident_of(v_ben), 'BC', 'for_payment')::text, true);
  perform set_config('t.pending', tests.request_in(tests.resident_of(v_cy), 'BC', 'pending')::text, true);
  perform set_config('t.treasurer', tests.create_staff('treasurer@example.test', 'treasurer')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
end $$;

select tests.act_as(current_setting('t.treasurer')::uuid);
select throws_ok(
  $$select public.record_payment(current_setting('t.req')::uuid, 'OR-1000', 40, 'cash')$$,
  'P0001', 'Amount must be exactly PHP 50.00.', 'The amount must equal the fee');
select throws_ok(
  $$select public.record_payment(current_setting('t.req')::uuid, 'OR-1000', 50, 'gcash')$$,
  'P0001', 'Enter the reference number for this payment.', 'E-wallet payments need a reference number');
select throws_ok(
  $$select public.record_payment(current_setting('t.pending')::uuid, 'OR-1000', 50, 'cash')$$,
  'P0001', 'This request is not waiting for payment.', 'Only requests waiting for payment can be paid');

select tests.act_as(current_setting('t.sec')::uuid);
select throws_ok(
  $$select public.record_payment(current_setting('t.req')::uuid, 'OR-1000', 50, 'cash')$$,
  'P0001', 'Only the Barangay Treasurer records payments.', 'Only the Treasurer records payments');

-- A valid payment; the OR number is stored in upper case and cannot be reused
select tests.act_as(current_setting('t.treasurer')::uuid);
do $$
begin
  perform set_config('t.pay', public.record_payment(current_setting('t.req')::uuid, 'or-1001', 50, 'cash')::text, true);
end $$;
select is((select or_number from public.payments where id = current_setting('t.pay')::uuid),
  'OR-1001', 'The OR number is stored in upper case');
select throws_ok(
  $$select public.record_payment(current_setting('t.req2')::uuid, 'OR-1001', 50, 'cash')$$,
  'P0001', 'OR number OR-1001 has already been used.', 'An OR number is used once');

-- Voiding keeps the row with its reason
select throws_ok(
  $$select public.void_payment(current_setting('t.pay')::uuid, 'short')$$,
  'P0001', 'Give a reason of 10 to 500 characters.', 'A void needs a reason of 10 to 500 characters');
select public.void_payment(current_setting('t.pay')::uuid, 'Wrong amount encoded');
select is((select status from public.payments where id = current_setting('t.pay')::uuid),
  'voided', 'A voided payment stays in the database');
select is((select void_reason from public.payments where id = current_setting('t.pay')::uuid),
  'Wrong amount encoded', 'The void reason is stored');
select isnt((select voided_at from public.payments where id = current_setting('t.pay')::uuid),
  null, 'The void time is stored');

reset role;
select set_config('request.jwt.claims', '', true);
select is((select count(*)::int from public.audit_logs
           where action = 'payment_void' and entity_id = current_setting('t.pay')),
  1, 'The void is audited');

select * from finish();
rollback;
