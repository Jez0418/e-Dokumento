-- =====================================================================
-- 11_close_account_on_exit.sql -- a resident who moved out or died can no
-- longer sign in.
-- Run after 01-10. Safe to rerun.
--
-- When the Secretary sets a resident's registry status to moved_out or
-- deceased, the linked online account is made inactive in the same
-- statement, so the next page load signs them out. Setting the status
-- back to active reactivates the account. Only resident-role accounts are
-- touched; staff accounts stay under the Administrator's control. The
-- change is written to the audit log.
-- =====================================================================

-- Let the sync below change profiles.status for the Secretary's session.
-- Same body as in 02_functions.sql, so running either file first is fine.
create or replace function public.guard_profile_update()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
begin
  if auth.uid() is null or public.has_role('admin')
     or coalesce(current_setting('app.account_sync', true), '') = 'on' then
    return new;
  end if;
  if new.id is distinct from old.id
     or new.role_id is distinct from old.role_id
     or new.status is distinct from old.status
     or new.email is distinct from old.email then
    raise exception 'You cannot change role, status or email from your profile.';
  end if;
  return new;
end;
$$;

create or replace function public.sync_account_with_registry()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_closed text[] := array['moved_out', 'deceased'];
  v_to     text;
  v_id     uuid;
begin
  if new.profile_id is null or new.status is not distinct from old.status then
    return new;
  end if;

  if new.status = any (v_closed) then
    v_to := 'inactive';
  elsif new.status = 'active' and old.status = any (v_closed) then
    v_to := 'active';
  else
    return new;
  end if;

  perform set_config('app.account_sync', 'on', true);
  update public.profiles p
     set status = v_to
   where p.id = new.profile_id
     and p.status is distinct from v_to
     and p.role_id = (select r.id from public.roles r where r.code = 'resident')
  returning p.id into v_id;
  perform set_config('app.account_sync', 'off', true);

  if v_id is not null then
    perform public.write_audit(
      case when v_to = 'inactive' then 'account_deactivated' else 'account_reactivated' end,
      'profiles', v_id::text,
      jsonb_build_object('resident_id', new.id, 'registry_status', new.status));
  end if;
  return new;
end;
$$;

drop trigger if exists residents_sync_account on public.residents;
create trigger residents_sync_account after update of status on public.residents
  for each row execute function public.sync_account_with_registry();

revoke execute on function public.sync_account_with_registry() from public, anon, authenticated;

-- Catch up residents already marked moved out or deceased.
do $$
begin
  perform set_config('app.account_sync', 'on', true);
  update public.profiles p
     set status = 'inactive'
    from public.residents r
   where r.profile_id = p.id
     and r.status in ('moved_out', 'deceased')
     and p.status = 'active'
     and p.role_id = (select ro.id from public.roles ro where ro.code = 'resident');
  perform set_config('app.account_sync', 'off', true);
end $$;
