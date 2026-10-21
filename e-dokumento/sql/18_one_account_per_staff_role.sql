-- =====================================================================
-- 18_one_account_per_staff_role.sql -- only one active account may hold
-- each staff role: Administrator, Punong Barangay, Secretary, Treasurer.
-- Run after 01-17. Safe to rerun.
--
-- Replaces the captain-only index from 13_one_captain_account.sql.
-- To hand over a role: change the current holder's role (or deactivate
-- them) under Users, then give the role to the new account. If two
-- accounts currently hold a role, this file fails until one is changed.
-- =====================================================================

do $$
declare
  v_ids text := (select string_agg(id::text, ',') from public.roles
                  where code in ('admin', 'captain', 'secretary', 'treasurer'));
begin
  drop index if exists public.profiles_one_captain_account;
  execute format(
    'create unique index if not exists profiles_one_account_per_staff_role on public.profiles (role_id) where role_id in (%s) and status = ''active''',
    v_ids);
end $$;
