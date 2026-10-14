-- =====================================================================
-- 13_one_captain_account.sql -- only one active account may hold the
-- Punong Barangay role.
-- Run after 01-12. Safe to rerun.
--
-- To hand over: change the current captain's role (or deactivate them)
-- under Users, then give the role to the new account. If two accounts
-- currently hold it, this file fails until one is changed.
-- =====================================================================

do $$
declare
  v_id smallint := (select id from public.roles where code = 'captain');
begin
  execute format(
    'create unique index if not exists profiles_one_captain_account on public.profiles (role_id) where role_id = %s and status = ''active''',
    v_id);
end $$;
