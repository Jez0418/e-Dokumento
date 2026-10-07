-- Test accounts, one per role. Run in the Supabase SQL Editor (after 01-06).
--
-- 1. Replace CHANGE_ME below with a password you choose (12+ characters).
--    Do NOT commit the real password to GitHub.
-- 2. Run the script. It is safe to rerun: existing emails are skipped.
-- 3. Delete the accounts when testing is done (see the bottom of this file).
--
-- Sign in with:  admin.test@example.test, captain.test@example.test,
--   secretary.test@example.test, treasurer.test@example.test, resident.test@example.test

do $seed$
declare
  v_password text := 'CHANGE_ME';
  v_purok    smallint;
  r          record;
  v_id       uuid;
begin
  if v_password = 'CHANGE_ME' or char_length(v_password) < 12 then
    raise exception 'Set v_password to your own password of at least 12 characters first.';
  end if;

  select id into v_purok from public.puroks order by id limit 1;
  if v_purok is null then
    raise exception 'No purok found. Run 05_seed.sql first.';
  end if;

  for r in
    select * from (values
      ('admin',     'admin.test@example.test',     'Test Administrator'),
      ('captain',   'captain.test@example.test',   'Test Punong Barangay'),
      ('secretary', 'secretary.test@example.test', 'Test Secretary'),
      ('treasurer', 'treasurer.test@example.test', 'Test Treasurer'),
      ('resident',  'resident.test@example.test',  'Test Resident')
    ) as t(role_code, email, full_name)
  loop
    if exists (select 1 from auth.users where email = r.email) then
      raise notice 'Skipped % (already exists)', r.email;
      continue;
    end if;

    v_id := gen_random_uuid();

    insert into auth.users (
      instance_id, id, aud, role, email, encrypted_password, email_confirmed_at,
      raw_app_meta_data, raw_user_meta_data, created_at, updated_at,
      confirmation_token, recovery_token, email_change_token_new, email_change)
    values (
      '00000000-0000-0000-0000-000000000000', v_id, 'authenticated', 'authenticated',
      r.email, extensions.crypt(v_password, extensions.gen_salt('bf')), now(),
      jsonb_build_object('provider', 'email', 'providers', jsonb_build_array('email'), 'app_role', r.role_code),
      case when r.role_code = 'resident' then
        jsonb_build_object('full_name', r.full_name, 'first_name', 'Test', 'last_name', 'Resident',
          'birth_date', '1995-05-15', 'sex', 'male', 'civil_status', 'single',
          'street_address', '1 Test Street', 'resident_since', '2015-01-01',
          'purok_id', v_purok, 'is_registered_voter', false)
      else jsonb_build_object('full_name', r.full_name) end,
      now(), now(), '', '', '', '');

    insert into auth.identities (id, user_id, provider_id, provider, identity_data, created_at, updated_at, last_sign_in_at)
    values (gen_random_uuid(), v_id, v_id::text, 'email',
            jsonb_build_object('sub', v_id::text, 'email', r.email, 'email_verified', true),
            now(), now(), now());

    raise notice 'Created % (%)', r.email, r.role_code;
  end loop;
end
$seed$;

-- Cleanup when testing is over (cascades to profiles and the test resident):
-- delete from auth.users where email like '%.test@example.test';
