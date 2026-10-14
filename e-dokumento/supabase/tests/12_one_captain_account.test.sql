-- Only one active account may hold the Punong Barangay role.
begin;
select plan(4);

do $$
begin
  perform set_config('t.cap', tests.create_staff('cap@example.test', 'captain')::text, true);
  perform set_config('t.sec', tests.create_staff('sec@example.test', 'secretary')::text, true);
end $$;

select throws_ok(
  $$select tests.create_staff('cap2@example.test', 'captain')$$,
  '23505', null, 'A second active captain account is refused');

select throws_ok(
  $$update public.profiles set role_id = (select id from public.roles where code = 'captain')
    where id = current_setting('t.sec')::uuid$$,
  '23505', null, 'Promoting another account to captain is refused');

-- Hand over: deactivate the old captain, then promote the new one
update public.profiles set status = 'inactive' where id = current_setting('t.cap')::uuid;
select lives_ok(
  $$update public.profiles set role_id = (select id from public.roles where code = 'captain')
    where id = current_setting('t.sec')::uuid$$,
  'The role can move once the old captain is inactive');

select throws_ok(
  $$update public.profiles set status = 'active' where id = current_setting('t.cap')::uuid$$,
  '23505', null, 'The old captain cannot be reactivated while another holds the role');

select * from finish();
rollback;
