-- =====================================================================
-- 10_captain_signature.sql -- the Punong Barangay's e-signature image.
-- Run after 01-09. Safe to rerun.
--
-- The image lives in the private "signatures" bucket and the active
-- Punong Barangay's barangay_officials.signature_path points at it.
--
--  * Only the one captain account linked to the seat (profile_id) may set
--    or replace it. Nobody can change their own role.
--  * Renaming the official, or ending/reinstating their service, clears the
--    signature and the account link, so it can never follow another name.
--  * Each issued certificate keeps a snapshot of the signature it was issued
--    with (issued_documents.signature_path); later changes never alter it.
--  * The image can be read only by the captain (own folder) and by users
--    who can see a certificate that uses it.
-- =====================================================================

alter table public.barangay_officials
  add column if not exists profile_id uuid references public.profiles (id) on delete set null;
create unique index if not exists barangay_officials_one_signer
  on public.barangay_officials (profile_id) where profile_id is not null and is_active;

alter table public.issued_documents
  add column if not exists signature_path text;

-- ---------------------------------------------------------------------
-- Storage
-- ---------------------------------------------------------------------
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('signatures', 'signatures', false, 1048576, array['image/jpeg', 'image/png'])
on conflict (id) do nothing;

drop policy if exists "Captain uploads own signature" on storage.objects;
create policy "Captain uploads own signature"
  on storage.objects for insert to authenticated
  with check (
    bucket_id = 'signatures'
    and (storage.foldername(name))[1] = auth.uid()::text
    and public.has_role('captain'));

-- Read: the captain's own images, and any image a certificate the caller can
-- see was issued with (issued_documents RLS applies to this subquery).
drop policy if exists "Signed-in users read signatures" on storage.objects;
drop policy if exists "Captain and certificate viewers read signatures" on storage.objects;
create policy "Captain and certificate viewers read signatures"
  on storage.objects for select to authenticated
  using (
    bucket_id = 'signatures'
    and (
      ((storage.foldername(name))[1] = auth.uid()::text and public.has_role('captain'))
      or exists (select 1 from public.issued_documents d where d.signature_path = name)));

drop policy if exists "Captain removes own signature" on storage.objects;
create policy "Captain removes own signature"
  on storage.objects for delete to authenticated
  using (
    bucket_id = 'signatures'
    and (storage.foldername(name))[1] = auth.uid()::text
    and public.has_role('captain')
    and not exists (select 1 from public.issued_documents d where d.signature_path = name));

-- ---------------------------------------------------------------------
-- Nobody changes their own role (the Users page only enforced this in PHP)
-- ---------------------------------------------------------------------
create or replace function public.guard_own_role()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
begin
  if auth.uid() is not null and new.id = auth.uid() and new.role_id is distinct from old.role_id then
    raise exception 'You cannot change your own role.';
  end if;
  return new;
end;
$$;

drop trigger if exists profiles_guard_own_role on public.profiles;
create trigger profiles_guard_own_role before update on public.profiles
  for each row execute function public.guard_own_role();

-- ---------------------------------------------------------------------
-- Signature guard on barangay_officials
-- ---------------------------------------------------------------------
create or replace function public.guard_signature_path()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_uid     uuid := auth.uid();
  v_cleared boolean := false;
begin
  if tg_op = 'INSERT' then
    if (new.signature_path is not null or new.profile_id is not null) and not public.has_role('captain') then
      raise exception 'Only the Punong Barangay can change the signature.';
    end if;
    return new;
  end if;

  -- A different name, position or service status means a different person: the
  -- signature and the account link do not carry over.
  if new.full_name is distinct from old.full_name
     or new.position is distinct from old.position
     or new.is_active is distinct from old.is_active then
    new.signature_path := null;
    v_cleared := true;
    if new.position is distinct from old.position or new.is_active is distinct from old.is_active then
      new.profile_id := null;
    end if;
  end if;

  -- Linking an account to the seat: only the captain, for themselves, on an unlinked seat.
  if new.profile_id is distinct from old.profile_id and new.profile_id is not null then
    if not (public.has_role('captain') and new.profile_id = v_uid) then
      raise exception 'Only the Punong Barangay can change the signature.';
    end if;
    if old.profile_id is not null then
      raise exception 'This signature belongs to another account. An administrator must reset it first.';
    end if;
  end if;

  -- An administrator resetting the seat (unlinking) also wipes the signature.
  if new.profile_id is distinct from old.profile_id and new.profile_id is null then
    if v_uid is not null and not public.has_role('admin') then
      raise exception 'Only an administrator can reset the signature link.';
    end if;
    new.signature_path := null;
    v_cleared := true;
  end if;

  if not v_cleared and new.signature_path is distinct from old.signature_path then
    if new.signature_path is not null then
      if not public.has_role('captain') then
        raise exception 'Only the Punong Barangay can change the signature.';
      end if;
      if coalesce(new.profile_id, old.profile_id) is distinct from v_uid then
        raise exception 'This signature belongs to another account. An administrator must reset it first.';
      end if;
    elsif v_uid is not null and not public.has_role('admin', 'captain') then
      raise exception 'Only the Punong Barangay can change the signature.';
    end if;
  end if;
  return new;
end;
$$;

drop trigger if exists barangay_officials_guard_signature on public.barangay_officials;
create trigger barangay_officials_guard_signature
  before insert or update on public.barangay_officials
  for each row execute function public.guard_signature_path();

-- ---------------------------------------------------------------------
-- The one supported way for the captain to set, replace or remove it.
-- The first captain to save links their account to the seat.
-- ---------------------------------------------------------------------
create or replace function public.set_captain_signature(p_path text)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_id      smallint;
  v_profile uuid;
begin
  if not public.has_role('captain') then
    raise exception 'Only the Punong Barangay can change the signature.';
  end if;
  if p_path is not null then
    if p_path !~ '^[0-9a-f-]{36}/[0-9a-f]{24}\.(png|jpg)$' then
      raise exception 'That signature file is not valid.';
    end if;
    if split_part(p_path, '/', 1) <> auth.uid()::text then
      raise exception 'That signature file is not in your folder.';
    end if;
  end if;

  select o.id, o.profile_id into v_id, v_profile
  from public.barangay_officials o
  where o.position = 'punong_barangay' and o.is_active
  order by o.term_start desc limit 1;
  if v_id is null then
    raise exception 'Add the active Punong Barangay under Officials first.';
  end if;
  if v_profile is not null and v_profile <> auth.uid() then
    raise exception 'This signature belongs to another account. An administrator must reset it first.';
  end if;

  update public.barangay_officials
     set signature_path = p_path, profile_id = coalesce(profile_id, auth.uid())
   where id = v_id;
end;
$$;

revoke all on function public.set_captain_signature(text) from public, anon;
grant execute on function public.set_captain_signature(text) to authenticated;

-- ---------------------------------------------------------------------
-- Snapshot: a certificate keeps the signature it was issued with, and only
-- types that need the captain's approval get one. Never changes afterwards.
-- ---------------------------------------------------------------------
create or replace function public.snapshot_signature()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
begin
  if tg_op = 'INSERT' then
    new.signature_path := (
      select o.signature_path
      from public.barangay_officials o
      join public.document_requests d on d.id = new.request_id
      join public.document_types t on t.id = d.document_type_id
      where o.id = new.signatory_id and t.requires_captain_approval);
  else
    new.signature_path := old.signature_path;
  end if;
  return new;
end;
$$;

drop trigger if exists issued_documents_snapshot_signature on public.issued_documents;
create trigger issued_documents_snapshot_signature
  before insert or update on public.issued_documents
  for each row execute function public.snapshot_signature();

-- ---------------------------------------------------------------------
-- Public certificate check: e_signed reflects the snapshot taken at issue.
-- ---------------------------------------------------------------------
create or replace function public.verify_document(p_code text)
returns jsonb
language plpgsql stable security definer
set search_path = ''
as $$
declare
  v jsonb;
  v_code text := upper(trim(coalesce(p_code, '')));
begin
  if v_code !~ '^[0-9A-F]{10}$' then
    return jsonb_build_object('found', false);
  end if;
  select jsonb_build_object(
           'found', true,
           'document_no', i.document_no,
           'document_type', t.name,
           'holder', concat_ws(' ', r.first_name,
                               case when coalesce(r.middle_name, '') <> '' then left(r.middle_name, 1) || '.' end,
                               r.last_name, r.suffix),
           'issued_at', i.issued_at,
           'valid_until', i.valid_until,
           'status', i.status,
           'expired', (i.valid_until is not null and i.valid_until < (now() at time zone 'Asia/Manila')::date),
           'signatory', o.full_name,
           'e_signed', (i.signature_path is not null and i.status <> 'revoked'))
    into v
  from public.issued_documents i
  join public.document_requests d on d.id = i.request_id
  join public.document_types t on t.id = d.document_type_id
  join public.residents r on r.id = d.resident_id
  join public.barangay_officials o on o.id = i.signatory_id
  where i.verification_code = v_code;
  return coalesce(v, jsonb_build_object('found', false));
end;
$$;

grant execute on function public.verify_document(text) to anon, authenticated;
