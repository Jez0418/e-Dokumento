-- 15. Keep a resident's account (profiles) and resident record (residents) in step.
--   residents.first/last/suffix -> profiles.full_name       (display name is derived from the record)
--   residents.contact_no        <-> profiles.contact_no     (either side can be edited)
-- Safe to run more than once.

create or replace function public.sync_resident_to_profile()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_name text;
  v_keep boolean;
  v_prev text := coalesce(current_setting('app.account_sync', true), '');
begin
  if new.profile_id is null then
    return new;
  end if;
  v_name := trim(concat_ws(' ', nullif(trim(new.first_name), ''), nullif(trim(new.last_name), ''), nullif(trim(new.suffix), '')));
  -- Linking a record to an account must not wipe the account's number; editing the same record can.
  if tg_op = 'INSERT' then
    v_keep := true;
  else
    v_keep := old.profile_id is distinct from new.profile_id;
  end if;
  perform set_config('app.account_sync', 'on', true);
  update public.profiles
     set full_name  = case when char_length(v_name) between 2 and 120 then v_name else full_name end,
         contact_no = case when v_keep then coalesce(new.contact_no, contact_no) else new.contact_no end
   where id = new.profile_id;
  perform set_config('app.account_sync', v_prev, true);
  return new;
end;
$$;

drop trigger if exists residents_sync_profile on public.residents;
create trigger residents_sync_profile
  after insert or update of first_name, last_name, suffix, contact_no, profile_id on public.residents
  for each row execute function public.sync_resident_to_profile();

create or replace function public.sync_profile_to_resident()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_prev text := coalesce(current_setting('app.workflow', true), '');
begin
  perform set_config('app.workflow', 'on', true);
  update public.residents
     set contact_no = new.contact_no
   where profile_id = new.id
     and contact_no is distinct from new.contact_no;
  perform set_config('app.workflow', v_prev, true);
  return new;
end;
$$;

drop trigger if exists profiles_sync_resident on public.profiles;
create trigger profiles_sync_resident
  after update of contact_no on public.profiles
  for each row execute function public.sync_profile_to_resident();

-- One-off backfill: the resident record is the source of truth for existing accounts.
do $$
begin
  perform set_config('app.account_sync', 'on', true);
  update public.profiles p
     set full_name  = trim(concat_ws(' ', nullif(trim(r.first_name), ''), nullif(trim(r.last_name), ''), nullif(trim(r.suffix), ''))),
         contact_no = coalesce(r.contact_no, p.contact_no)
    from public.residents r
   where r.profile_id = p.id
     and char_length(trim(concat_ws(' ', nullif(trim(r.first_name), ''), nullif(trim(r.last_name), ''), nullif(trim(r.suffix), '')))) between 2 and 120;
  perform set_config('app.account_sync', 'off', true);
end $$;
