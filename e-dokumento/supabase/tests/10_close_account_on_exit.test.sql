-- Moving out or dying closes the resident's online account; going back to active reopens it.
begin;
select plan(9);

do $$
begin
  perform set_config('t.ana', tests.create_resident('ana@example.test', 'Ana', 'Reyes')::text, true);
  perform set_config('t.ben', tests.create_resident('ben@example.test', 'Ben', 'Santos')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
  perform set_config('t.ana_res', tests.resident_of(current_setting('t.ana')::uuid)::text, true);
  perform set_config('t.ben_res', tests.resident_of(current_setting('t.ben')::uuid)::text, true);
end $$;

select tests.act_as(current_setting('t.sec')::uuid);
update public.residents set status = 'deceased' where id = current_setting('t.ana_res')::uuid;
update public.residents set status = 'moved_out' where id = current_setting('t.ben_res')::uuid;

reset role;
select set_config('request.jwt.claims', '', true);
select is((select status from public.profiles where id = current_setting('t.ana')::uuid),
  'inactive', 'Marking deceased deactivates the account');
select is((select status from public.profiles where id = current_setting('t.ben')::uuid),
  'inactive', 'Marking moved out deactivates the account');
select is((select count(*)::int from public.audit_logs
           where action = 'account_deactivated' and entity_id = current_setting('t.ana')),
  1, 'The deactivation is audited');
select is((select status from public.profiles where id = current_setting('t.sec')::uuid),
  'active', 'Staff accounts are not touched');

-- Reopening
select tests.act_as(current_setting('t.sec')::uuid);
update public.residents set status = 'active' where id = current_setting('t.ben_res')::uuid;
reset role;
select set_config('request.jwt.claims', '', true);
select is((select status from public.profiles where id = current_setting('t.ben')::uuid),
  'active', 'Setting the resident back to active reactivates the account');

-- Inactive (not moved out / deceased) leaves the account alone
select tests.act_as(current_setting('t.sec')::uuid);
update public.residents set status = 'inactive' where id = current_setting('t.ben_res')::uuid;
reset role;
select set_config('request.jwt.claims', '', true);
select is((select status from public.profiles where id = current_setting('t.ben')::uuid),
  'active', 'Inactive registry status does not close the account');

-- A resident still cannot change their own account status or registry status
select tests.act_as(current_setting('t.ben')::uuid);
select throws_ok(
  $$update public.profiles set status = 'inactive' where id = current_setting('t.ben')::uuid$$,
  'P0001', 'You cannot change role, status or email from your profile.',
  'A resident cannot change their own account status');
select throws_ok(
  $$update public.residents set status = 'deceased' where id = current_setting('t.ben_res')::uuid$$,
  'P0001', 'Your record is locked while it is verified or under review. Visit the barangay hall to correct it.',
  'A verified resident cannot change their own registry status');

reset role;
select set_config('request.jwt.claims', '', true);
select is(coalesce(current_setting('app.account_sync', true), ''), 'off', 'The sync switch is reset afterwards');

select * from finish();
rollback;
