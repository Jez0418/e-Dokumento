-- =====================================================================
-- 10_captain_signature.sql -- the Punong Barangay's e-signature image.
-- Run after 01-09. Safe to rerun.
--
-- The image lives in the private "signatures" bucket and the active
-- Punong Barangay's barangay_officials.signature_path points at it. Only a
-- user with the captain role may set, replace or remove it: a trigger blocks
-- every other writer (administrators included) and set_captain_signature()
-- is the one supported way in.
-- =====================================================================

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

-- The image is printed on certificates every signed-in user can open, so any
-- signed-in user may read it. Access is still by a 60-second signed URL.
drop policy if exists "Signed-in users read signatures" on storage.objects;
create policy "Signed-in users read signatures"
  on storage.objects for select to authenticated
  using (bucket_id = 'signatures');

drop policy if exists "Captain removes own signature" on storage.objects;
create policy "Captain removes own signature"
  on storage.objects for delete to authenticated
  using (
    bucket_id = 'signatures'
    and (storage.foldername(name))[1] = auth.uid()::text
    and public.has_role('captain'));

-- ---------------------------------------------------------------------
-- Only the captain may change signature_path
-- ---------------------------------------------------------------------
create or replace function public.guard_signature_path()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
begin
  if (tg_op = 'INSERT' and new.signature_path is not null)
     or (tg_op = 'UPDATE' and new.signature_path is distinct from old.signature_path) then
    if not public.has_role('captain') then
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
-- The one supported way to set, replace or remove the signature
-- ---------------------------------------------------------------------
create or replace function public.set_captain_signature(p_path text)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_id smallint;
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

  select o.id into v_id
  from public.barangay_officials o
  where o.position = 'punong_barangay' and o.is_active
  order by o.term_start desc limit 1;
  if v_id is null then
    raise exception 'Add the active Punong Barangay under Officials first.';
  end if;

  update public.barangay_officials set signature_path = p_path where id = v_id;
  perform public.write_audit(case when p_path is null then 'signature_remove' else 'signature_set' end,
    'barangay_officials', v_id::text, '{}'::jsonb);
end;
$$;

revoke all on function public.set_captain_signature(text) from public, anon;
grant execute on function public.set_captain_signature(text) to authenticated;

-- ---------------------------------------------------------------------
-- Public certificate check: now also says whether the certificate is
-- electronically signed (captain-approval type and a signature on file).
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
           'e_signed', (t.requires_captain_approval and i.status <> 'revoked' and o.signature_path is not null))
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
