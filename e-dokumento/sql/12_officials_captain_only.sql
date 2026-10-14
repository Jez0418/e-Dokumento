-- =====================================================================
-- 12_officials_captain_only.sql -- the Officials list holds only the
-- Punong Barangay.
-- Run after 01-11. Safe to rerun.
--
-- Only the Punong Barangay is read by the system (the certificate
-- signatory and e-signature). Secretary, Treasurer and the other roles are
-- user accounts, set up under Users, so they no longer belong here.
--
--  * Deletes official rows for every other position. Nothing references
--    them (issued documents point only at the Punong Barangay).
--  * Narrows the position check so only punong_barangay can be stored.
-- =====================================================================

delete from public.barangay_officials where position <> 'punong_barangay';

do $$
declare
  c record;
begin
  for c in
    select conname from pg_constraint
     where conrelid = 'public.barangay_officials'::regclass
       and contype = 'c'
       and pg_get_constraintdef(oid) ilike '%position%'
  loop
    execute format('alter table public.barangay_officials drop constraint %I', c.conname);
  end loop;
end $$;

alter table public.barangay_officials
  add constraint barangay_officials_position_check check (position = 'punong_barangay');
