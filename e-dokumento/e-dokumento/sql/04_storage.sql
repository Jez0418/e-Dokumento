-- =====================================================================
-- 04_storage.sql -- private buckets and Storage RLS
-- Paths are "<resident_id>/<random>.<ext>", so the first folder names the owner.
-- =====================================================================

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values
  ('verification-ids', 'verification-ids', false, 2097152, array['image/jpeg', 'image/png', 'application/pdf']),
  ('request-files',    'request-files',    false, 2097152, array['image/jpeg', 'image/png', 'application/pdf'])
on conflict (id) do nothing;

create policy "Residents upload own verification IDs"
  on storage.objects for insert to authenticated
  with check (
    bucket_id = 'verification-ids'
    and (storage.foldername(name))[1] = public.my_resident_id()::text);

create policy "Owner and records staff read verification IDs"
  on storage.objects for select to authenticated
  using (
    bucket_id = 'verification-ids'
    and ((storage.foldername(name))[1] = public.my_resident_id()::text
         or public.has_role('admin', 'captain', 'secretary')));

create policy "Owner or Secretary uploads request files"
  on storage.objects for insert to authenticated
  with check (
    bucket_id = 'request-files'
    and ((storage.foldername(name))[1] = public.my_resident_id()::text
         or public.has_role('secretary')));

create policy "Owner and records staff read request files"
  on storage.objects for select to authenticated
  using (
    bucket_id = 'request-files'
    and ((storage.foldername(name))[1] = public.my_resident_id()::text
         or public.has_role('admin', 'captain', 'secretary')));

-- Lets PHP clean up a file whose request failed to save; files already
-- referenced by a request or verification can never be removed.
create policy "Uploader removes unattached files"
  on storage.objects for delete to authenticated
  using (
    bucket_id in ('verification-ids', 'request-files')
    and ((storage.foldername(name))[1] = public.my_resident_id()::text or public.has_role('secretary'))
    and not exists (select 1 from public.request_attachments a where a.file_path = name)
    and not exists (select 1 from public.resident_verifications v
                    where v.front_image_path = name or v.back_image_path = name));
