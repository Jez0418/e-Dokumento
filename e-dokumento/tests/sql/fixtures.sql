create extension if not exists pgtap with schema extensions;

-- =====================================================================
-- Test fixtures for the local Supabase stack only (tests/sql/run.sh).
-- Data helpers run as postgres; act_as / act_as_anon switch the caller
-- for the rest of the transaction. Every test file rolls back.
-- =====================================================================

create schema if not exists tests;
grant usage on schema tests to anon, authenticated;

-- A staff account. The handle_new_user trigger creates the profile with the role.
create or replace function tests.create_staff(p_email text, p_role text)
returns uuid
language plpgsql
as $$
declare
  v_id uuid := gen_random_uuid();
begin
  insert into auth.users (id, instance_id, aud, role, email, encrypted_password, email_confirmed_at,
                          raw_app_meta_data, raw_user_meta_data, created_at, updated_at)
  values (v_id, '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', p_email, '', now(),
          jsonb_build_object('app_role', p_role), jsonb_build_object('full_name', p_email), now(), now());
  return v_id;
end;
$$;

-- A resident account with its residents row (created by handle_new_user).
create or replace function tests.create_resident(p_email text, p_first text, p_last text, p_verified boolean default true)
returns uuid
language plpgsql
as $$
declare
  v_id uuid := gen_random_uuid();
begin
  insert into auth.users (id, instance_id, aud, role, email, encrypted_password, email_confirmed_at,
                          raw_app_meta_data, raw_user_meta_data, created_at, updated_at)
  values (v_id, '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', p_email, '', now(),
          '{}'::jsonb,
          jsonb_build_object(
            'full_name', p_first || ' ' || p_last,
            'first_name', p_first,
            'last_name', p_last,
            'purok_id', (select id from public.puroks where name = 'Purok 1'),
            'birth_date', '1990-01-01',
            'sex', 'female',
            'civil_status', 'single',
            'street_address', '123 Rizal St',
            'resident_since', '2015-01-01'),
          now(), now());
  if p_verified then
    update public.residents set verification_status = 'verified' where profile_id = v_id;
  end if;
  return v_id;
end;
$$;

create or replace function tests.resident_of(p_profile uuid)
returns uuid
language sql stable
as $$
  select id from public.residents where profile_id = p_profile;
$$;

create or replace function tests.type_id(p_code text)
returns smallint
language sql stable
as $$
  select id from public.document_types where code = p_code;
$$;

create or replace function tests.purpose_id()
returns smallint
language sql stable
as $$
  select id from public.purposes where name = 'Employment';
$$;

create or replace function tests.id_attachments(p_resident uuid)
returns jsonb
language sql stable
as $$
  select jsonb_build_array(jsonb_build_object(
    'path', p_resident::text || '/id.pdf',
    'name', 'id.pdf',
    'mime', 'application/pdf',
    'size', 1000,
    'requirement_id', (select id from public.requirements where name = 'Valid government-issued ID')));
$$;

-- A request inserted directly in the given status (bypasses the workflow functions).
create or replace function tests.request_in(p_resident uuid, p_code text, p_status text)
returns uuid
language plpgsql
as $$
declare
  v_id uuid;
begin
  insert into public.document_requests (resident_id, document_type_id, purpose_id, channel, fee_amount,
                                        status, requested_by, released_at, released_to, rejection_reason)
  select p_resident, t.id, tests.purpose_id(), 'walk_in', t.fee, p_status, r.profile_id,
         case when p_status = 'released' then now() end,
         case when p_status = 'released' then 'Test Claimant' end,
         case when p_status = 'rejected' then 'Rejected in test fixture' end
  from public.document_types t
  cross join public.residents r
  where t.code = p_code and r.id = p_resident
  returning id into v_id;
  return v_id;
end;
$$;

create or replace function tests.add_captain()
returns smallint
language sql
as $$
  insert into public.barangay_officials (full_name, position, term_start, term_end, is_active)
  values ('Test Punong Barangay', 'punong_barangay', date '2025-07-01', date '2028-06-30', true)
  returning id;
$$;

-- Role switchers. Not security definer: SET ROLE is not allowed inside one.
create or replace function tests.act_as(p_user uuid)
returns void
language plpgsql
as $$
begin
  perform set_config('request.jwt.claims', jsonb_build_object('sub', p_user, 'role', 'authenticated')::text, true);
  set local role authenticated;
end;
$$;

create or replace function tests.act_as_anon()
returns void
language plpgsql
as $$
begin
  perform set_config('request.jwt.claims', '{"role": "anon"}', true);
  set local role anon;
end;
$$;

revoke execute on all functions in schema tests from public;
grant execute on function tests.act_as(uuid), tests.act_as_anon() to anon, authenticated;
-- Read-only catalogue lookups are safe under any role.
grant execute on function tests.type_id(text), tests.purpose_id(), tests.id_attachments(uuid) to anon, authenticated;
