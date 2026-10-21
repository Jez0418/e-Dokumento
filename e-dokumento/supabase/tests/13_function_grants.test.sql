-- Function grants: the API may call only what the app needs; RLS helpers stay usable by signed-in users.
begin;
select plan(9);

select ok(not has_function_privilege('anon', 'public.is_staff()', 'execute'), 'anon cannot call is_staff');
select ok(not has_function_privilege('anon', 'public.has_role(text[])', 'execute'), 'anon cannot call has_role');
select ok(not has_function_privilege('anon', 'public.my_resident_id()', 'execute'), 'anon cannot call my_resident_id');
select ok(not has_function_privilege('anon', 'public.current_role_code()', 'execute'), 'anon cannot call current_role_code');

select ok(has_function_privilege('authenticated', 'public.is_staff()', 'execute'), 'signed-in users keep is_staff for RLS');
select ok(has_function_privilege('authenticated', 'public.has_role(text[])', 'execute'), 'signed-in users keep has_role for RLS');
select ok(has_function_privilege('authenticated', 'public.my_resident_id()', 'execute'), 'signed-in users keep my_resident_id for RLS');

select ok(has_function_privilege('anon', 'public.verify_document(text)', 'execute'), 'anon can still verify a certificate');

select ok(
  not exists (
    select 1
    from unnest(array[
      'public.guard_own_role()',
      'public.guard_signature_path()',
      'public.snapshot_signature()',
      'public.sync_resident_to_profile()',
      'public.sync_profile_to_resident()'
    ]) as f
    where has_function_privilege('anon', f, 'execute') or has_function_privilege('authenticated', f, 'execute')),
  'trigger-only functions are not callable over the API');

select * from finish();
rollback;
