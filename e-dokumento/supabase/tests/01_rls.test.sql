-- Row Level Security: each role sees only the rows README's security model allows.
begin;
select plan(14);

-- Setup as postgres. Ids live in transaction-local settings so every role can read them.
do $$
declare
  v_ana uuid := tests.create_resident('ana@example.test', 'Ana', 'Reyes');
begin
  perform set_config('t.ana', v_ana::text, true);
  perform set_config('t.ben', tests.create_resident('ben@example.test', 'Ben', 'Santos')::text, true);
  perform set_config('t.treasurer', tests.create_staff('treasurer@example.test', 'treasurer')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform tests.request_in(tests.resident_of(v_ana), 'COR', 'pending');
end $$;

select is(
  (select string_agg(tablename, ', ') from pg_tables where schemaname = 'public' and not rowsecurity),
  null, 'RLS is enabled on every public table');

select ok(
  (select 'security_invoker=on' = any (reloptions) from pg_class where oid = 'public.request_list'::regclass),
  'request_list runs with the caller''s RLS');
select ok(
  (select 'security_invoker=on' = any (reloptions) from pg_class where oid = 'public.payment_list'::regclass),
  'payment_list runs with the caller''s RLS');
select ok(
  (select 'security_invoker=on' = any (reloptions) from pg_class where oid = 'public.issued_document_list'::regclass),
  'issued_document_list runs with the caller''s RLS');

select is(
  (select count(*)::int from storage.buckets where id in ('verification-ids', 'request-files') and not public),
  2, 'Both storage buckets are private');

select ok((select count(*) from public.audit_logs) > 0, 'The audit log has rows to hide (precondition)');

-- Resident ana
select tests.act_as(current_setting('t.ana')::uuid);
select is((select count(*)::int from public.residents), 1, 'A resident sees one residents row');
select is((select profile_id from public.residents), current_setting('t.ana')::uuid, 'That row is their own');
select is((select count(*)::int from public.document_requests), 1, 'A resident sees their own request');

-- Resident ben
select tests.act_as(current_setting('t.ben')::uuid);
select is((select count(*)::int from public.document_requests), 0, 'A resident does not see another resident''s request');
select is((select count(*)::int from public.audit_logs), 0, 'A resident cannot read the audit log');

-- Treasurer
select tests.act_as(current_setting('t.treasurer')::uuid);
select is((select count(*)::int from public.audit_logs), 0, 'The treasurer cannot read the audit log');

-- Secretary
select tests.act_as(current_setting('t.sec')::uuid);
select is((select count(*)::int from public.residents), 2, 'The secretary sees every resident');

-- Anonymous
reset role;
select tests.act_as_anon();
select throws_ok('select count(*) from public.residents', '42501', null, 'Anonymous callers cannot read residents');

reset role;
select * from finish();
rollback;
