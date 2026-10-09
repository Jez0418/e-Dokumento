-- =====================================================================
-- 02_functions.sql -- role helpers, signup trigger, guards, audit,
-- workflow functions (the only way to change request state), reports.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Role helpers (used by RLS). SECURITY DEFINER so they can read profiles
-- without recursing through profiles' own RLS.
-- ---------------------------------------------------------------------
create or replace function public.current_role_code()
returns text
language sql stable security definer
set search_path = ''
as $$
  select r.code
  from public.profiles p
  join public.roles r on r.id = p.role_id
  where p.id = auth.uid() and p.status = 'active';
$$;

create or replace function public.has_role(variadic p_roles text[])
returns boolean
language sql stable security definer
set search_path = ''
as $$
  select coalesce(public.current_role_code() = any (p_roles), false);
$$;

create or replace function public.is_staff()
returns boolean
language sql stable security definer
set search_path = ''
as $$
  select public.has_role('admin', 'captain', 'secretary', 'treasurer');
$$;

create or replace function public.my_resident_id()
returns uuid
language sql stable security definer
set search_path = ''
as $$
  select r.id from public.residents r where r.profile_id = auth.uid();
$$;

-- ---------------------------------------------------------------------
-- Internal helpers (not callable by clients; see grants at the end)
-- ---------------------------------------------------------------------
create or replace function public.write_audit(p_action text, p_entity_type text, p_entity_id text,
                                              p_details jsonb default '{}'::jsonb)
returns void
language sql security definer
set search_path = ''
as $$
  insert into public.audit_logs (actor_id, action, entity_type, entity_id, details)
  values (auth.uid(), p_action, p_entity_type, p_entity_id, coalesce(p_details, '{}'::jsonb));
$$;

create or replace function public.notify_profile(p_profile uuid, p_request uuid, p_title text, p_message text)
returns void
language sql security definer
set search_path = ''
as $$
  insert into public.notifications (recipient_id, request_id, title, message)
  select p_profile, p_request, left(p_title, 120), p_message
  where p_profile is not null;
$$;

create or replace function public.notify_role(p_role text, p_request uuid, p_title text, p_message text)
returns void
language sql security definer
set search_path = ''
as $$
  insert into public.notifications (recipient_id, request_id, title, message)
  select p.id, p_request, left(p_title, 120), p_message
  from public.profiles p
  join public.roles r on r.id = p.role_id
  where r.code = p_role and p.status = 'active';
$$;

-- ---------------------------------------------------------------------
-- New Auth user -> profile (+ resident record for self-registration).
-- Role comes from app_metadata.app_role, which only the server (secret key)
-- can set. Self-signups always become residents.
-- ---------------------------------------------------------------------
create or replace function public.handle_new_user()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_meta      jsonb := coalesce(new.raw_user_meta_data, '{}'::jsonb);
  v_role_code text  := coalesce(new.raw_app_meta_data ->> 'app_role', 'resident');
  v_role_id   smallint;
  v_name      text;
  v_existing  uuid;
begin
  select id into v_role_id from public.roles where code = v_role_code;
  if v_role_id is null then
    v_role_code := 'resident';
    select id into v_role_id from public.roles where code = 'resident';
  end if;

  v_name := coalesce(nullif(trim(v_meta ->> 'full_name'), ''), split_part(new.email, '@', 1));
  if char_length(v_name) < 2 then v_name := new.email; end if;

  insert into public.profiles (id, role_id, email, full_name, contact_no)
  values (new.id, v_role_id, lower(new.email), left(v_name, 120),
          case when (v_meta ->> 'contact_no') ~ '^(09|\+639)[0-9]{9}$' then v_meta ->> 'contact_no' end);

  if v_role_code = 'resident' and coalesce(v_meta ->> 'first_name', '') <> '' then
    -- Link to an existing walk-in record for the same person, if one exists and is unclaimed
    select r.id into v_existing
    from public.residents r
    where lower(r.last_name) = lower(trim(v_meta ->> 'last_name'))
      and lower(r.first_name) = lower(trim(v_meta ->> 'first_name'))
      and lower(coalesce(r.middle_name, '')) = lower(coalesce(trim(v_meta ->> 'middle_name'), ''))
      and r.birth_date = (v_meta ->> 'birth_date')::date
    limit 1;

    if v_existing is not null then
      update public.residents
         set profile_id = new.id,
             email = coalesce(email, lower(new.email))
       where id = v_existing and profile_id is null;
    else
      insert into public.residents (
        profile_id, purok_id, first_name, middle_name, last_name, suffix, birth_date, sex, civil_status,
        street_address, contact_no, email, occupation, resident_since, is_registered_voter, created_by)
      values (
        new.id,
        (v_meta ->> 'purok_id')::smallint,
        trim(v_meta ->> 'first_name'),
        nullif(trim(v_meta ->> 'middle_name'), ''),
        trim(v_meta ->> 'last_name'),
        nullif(trim(v_meta ->> 'suffix'), ''),
        (v_meta ->> 'birth_date')::date,
        v_meta ->> 'sex',
        v_meta ->> 'civil_status',
        trim(v_meta ->> 'street_address'),
        case when (v_meta ->> 'contact_no') ~ '^(09|\+639)[0-9]{9}$' then v_meta ->> 'contact_no' end,
        lower(new.email),
        nullif(trim(v_meta ->> 'occupation'), ''),
        (v_meta ->> 'resident_since')::date,
        coalesce((v_meta ->> 'is_registered_voter')::boolean, false),
        new.id);
    end if;
  end if;
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

-- ---------------------------------------------------------------------
-- Column guards (RLS decides WHICH rows; these decide WHICH columns)
-- Workflow functions set app.workflow = 'on' for their transaction.
-- ---------------------------------------------------------------------
create or replace function public.guard_profile_update()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
begin
  if auth.uid() is null or public.has_role('admin') then
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

create trigger profiles_guard before update on public.profiles
  for each row execute function public.guard_profile_update();

create or replace function public.guard_resident_update()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
begin
  if auth.uid() is null
     or coalesce(current_setting('app.workflow', true), '') = 'on'
     or public.has_role('secretary') then
    return new;
  end if;
  -- From here on the caller is the resident editing their own row (RLS ensured ownership).
  if old.verification_status in ('pending', 'verified') then
    raise exception 'Your record is locked while it is verified or under review. Visit the barangay hall to correct it.';
  end if;
  if new.profile_id is distinct from old.profile_id
     or new.verification_status is distinct from old.verification_status
     or new.verified_by is distinct from old.verified_by
     or new.verified_at is distinct from old.verified_at
     or new.status is distinct from old.status
     or new.created_by is distinct from old.created_by then
    raise exception 'You cannot change verification or registry status.';
  end if;
  return new;
end;
$$;

create trigger residents_guard before update on public.residents
  for each row execute function public.guard_resident_update();

-- ---------------------------------------------------------------------
-- Generic audit trigger for registry and catalogue tables
-- ---------------------------------------------------------------------
create or replace function public.audit_row_change()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_old jsonb;
  v_new jsonb;
  v_id  text;
  v_changes jsonb;
begin
  if tg_op = 'DELETE' then
    v_old := to_jsonb(old);
    v_id := coalesce(v_old ->> 'id', v_old ->> 'key', v_old ->> 'document_type_id');
    insert into public.audit_logs (actor_id, action, entity_type, entity_id, details)
    values (auth.uid(), 'delete', tg_table_name, v_id, jsonb_build_object('old', v_old));
    return old;
  elsif tg_op = 'INSERT' then
    v_new := to_jsonb(new);
    v_id := coalesce(v_new ->> 'id', v_new ->> 'key', v_new ->> 'document_type_id');
    insert into public.audit_logs (actor_id, action, entity_type, entity_id, details)
    values (auth.uid(), 'create', tg_table_name, v_id, jsonb_build_object('new', v_new));
    return new;
  end if;

  v_old := to_jsonb(old);
  v_new := to_jsonb(new);
  v_id := coalesce(v_new ->> 'id', v_new ->> 'key', v_new ->> 'document_type_id');
  select coalesce(jsonb_object_agg(n.key, jsonb_build_object('from', v_old -> n.key, 'to', n.value)), '{}'::jsonb)
    into v_changes
  from jsonb_each(v_new) as n(key, value)
  where n.key not in ('updated_at', 'last_login_at')
    and (v_old -> n.key) is distinct from n.value;

  if v_changes <> '{}'::jsonb then
    insert into public.audit_logs (actor_id, action, entity_type, entity_id, details)
    values (auth.uid(), 'update', tg_table_name, v_id, jsonb_build_object('changes', v_changes));
  end if;
  return new;
end;
$$;

create trigger audit_profiles after update on public.profiles for each row execute function public.audit_row_change();
create trigger audit_residents after insert or update or delete on public.residents for each row execute function public.audit_row_change();
create trigger audit_puroks after insert or update or delete on public.puroks for each row execute function public.audit_row_change();
create trigger audit_purposes after insert or update or delete on public.purposes for each row execute function public.audit_row_change();
create trigger audit_id_types after insert or update or delete on public.id_types for each row execute function public.audit_row_change();
create trigger audit_requirements after insert or update or delete on public.requirements for each row execute function public.audit_row_change();
create trigger audit_document_types after insert or update or delete on public.document_types for each row execute function public.audit_row_change();
create trigger audit_dtr after insert or update or delete on public.document_type_requirements for each row execute function public.audit_row_change();
create trigger audit_officials after insert or update or delete on public.barangay_officials for each row execute function public.audit_row_change();
create trigger audit_settings after insert or update or delete on public.system_settings for each row execute function public.audit_row_change();

-- ---------------------------------------------------------------------
-- Client-callable event log (login, logout, print, export)
-- ---------------------------------------------------------------------
create or replace function public.log_event(p_action text, p_entity_type text, p_entity_id text default null,
                                            p_details jsonb default '{}'::jsonb,
                                            p_ip text default null, p_user_agent text default null)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_ip inet;
begin
  if auth.uid() is null then
    raise exception 'Sign in first.';
  end if;
  if p_action not in ('login', 'logout', 'print', 'export', 'view_file') then
    raise exception 'Unsupported audit action.';
  end if;
  begin
    v_ip := nullif(p_ip, '')::inet;
  exception when others then
    v_ip := null;
  end;
  insert into public.audit_logs (actor_id, action, entity_type, entity_id, details, ip_address, user_agent)
  values (auth.uid(), p_action, left(coalesce(p_entity_type, 'auth'), 60), left(p_entity_id, 100),
          coalesce(p_details, '{}'::jsonb), v_ip, left(p_user_agent, 300));
  if p_action = 'login' then
    update public.profiles set last_login_at = now() where id = auth.uid();
  end if;
end;
$$;

-- ---------------------------------------------------------------------
-- Resident verification
-- ---------------------------------------------------------------------
create or replace function public.submit_verification(p_id_type_id integer, p_id_number text,
                                                      p_front_path text, p_back_path text default null)
returns uuid
language plpgsql security definer
set search_path = ''
as $$
declare
  v_res public.residents%rowtype;
  v_id  uuid;
begin
  if not public.has_role('resident') then
    raise exception 'Only residents submit their own ID for verification.';
  end if;
  select * into v_res from public.residents where profile_id = auth.uid();
  if not found then
    raise exception 'No resident record is linked to your account. Visit the barangay hall.';
  end if;
  if v_res.verification_status = 'verified' then
    raise exception 'Your residency is already verified.';
  end if;
  if v_res.verification_status = 'pending' then
    raise exception 'Your ID is already being reviewed.';
  end if;
  if not exists (select 1 from public.id_types where id = p_id_type_id and is_active) then
    raise exception 'Choose a valid ID type.';
  end if;
  if p_id_number is null or char_length(trim(p_id_number)) not between 3 and 40 then
    raise exception 'Enter the ID number as printed on the ID.';
  end if;
  if coalesce(p_front_path, '') not like v_res.id::text || '/%'
     or (p_back_path is not null and p_back_path not like v_res.id::text || '/%') then
    raise exception 'Invalid file reference.';
  end if;

  perform set_config('app.workflow', 'on', true);
  insert into public.resident_verifications (resident_id, id_type_id, id_number, front_image_path, back_image_path)
  values (v_res.id, p_id_type_id, trim(p_id_number), p_front_path, p_back_path)
  returning id into v_id;
  update public.residents set verification_status = 'pending' where id = v_res.id;

  perform public.notify_role('secretary', null, 'ID submitted for verification',
                             format('%s %s submitted an ID for verification.', v_res.first_name, v_res.last_name));
  perform public.write_audit('verification_submit', 'resident_verifications', v_id::text,
                             jsonb_build_object('resident_id', v_res.id));
  return v_id;
end;
$$;

create or replace function public.review_verification(p_verification_id uuid, p_decision text, p_remarks text default null)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_ver public.resident_verifications%rowtype;
  v_res public.residents%rowtype;
  v_remarks text := nullif(trim(coalesce(p_remarks, '')), '');
begin
  if not public.has_role('secretary') then
    raise exception 'Only the Barangay Secretary reviews verifications.';
  end if;
  if p_decision not in ('approved', 'rejected') then
    raise exception 'Choose approve or reject.';
  end if;
  select * into v_ver from public.resident_verifications where id = p_verification_id for update;
  if not found or v_ver.status <> 'pending' then
    raise exception 'This submission is no longer pending.';
  end if;
  if p_decision = 'rejected' and (v_remarks is null or char_length(v_remarks) not between 10 and 500) then
    raise exception 'Give a reason of 10 to 500 characters so the resident knows what to fix.';
  end if;
  select * into v_res from public.residents where id = v_ver.resident_id;

  perform set_config('app.workflow', 'on', true);
  update public.resident_verifications
     set status = p_decision, remarks = v_remarks, reviewed_by = auth.uid(), reviewed_at = now()
   where id = v_ver.id;
  update public.residents
     set verification_status = case when p_decision = 'approved' then 'verified' else 'rejected' end,
         verified_by = case when p_decision = 'approved' then auth.uid() else null end,
         verified_at = case when p_decision = 'approved' then now() else null end
   where id = v_res.id;

  perform public.notify_profile(v_res.profile_id, null,
    case when p_decision = 'approved' then 'Residency verified' else 'Verification not approved' end,
    case when p_decision = 'approved' then 'You can now request barangay documents online.'
         else 'Your ID was not approved: ' || v_remarks end);
  perform public.write_audit('verification_' || p_decision, 'residents', v_res.id::text,
                             jsonb_build_object('verification_id', v_ver.id));
end;
$$;

-- ---------------------------------------------------------------------
-- Submit a document request (resident online, or Secretary walk-in)
-- p_attachments: [{"requirement_id":1,"path":"<resident>/<file>","name":"id.jpg","mime":"image/jpeg","size":12345}]
-- ---------------------------------------------------------------------
create or replace function public.submit_request(
  p_resident_id uuid, p_document_type_id integer, p_purpose_id integer,
  p_purpose_details text default null, p_copies integer default 1,
  p_extra jsonb default '{}'::jsonb, p_attachments jsonb default '[]'::jsonb,
  p_fee_waived boolean default false, p_waiver_reason text default null)
returns jsonb
language plpgsql security definer
set search_path = ''
as $$
declare
  v_role    text := public.current_role_code();
  v_res     public.residents%rowtype;
  v_type    public.document_types%rowtype;
  v_channel text;
  v_months  integer;
  v_fee     numeric(10,2);
  v_id      uuid;
  v_ctrl    text;
  v_att     jsonb;
  v_missing text;
  v_waived  boolean := coalesce(p_fee_waived, false);
begin
  if v_role is null then
    raise exception 'Sign in to submit a request.';
  end if;
  if v_role not in ('resident', 'secretary') then
    raise exception 'Only residents and the Barangay Secretary can file requests.';
  end if;

  select * into v_res from public.residents where id = p_resident_id;
  if not found then
    raise exception 'Resident record not found.';
  end if;
  if v_res.status <> 'active' then
    raise exception 'This resident record is not active.';
  end if;

  perform set_config('app.workflow', 'on', true);

  if v_role = 'resident' then
    if v_res.profile_id is distinct from auth.uid() then
      raise exception 'You can only request documents for yourself.';
    end if;
    if v_res.verification_status <> 'verified' then
      raise exception 'Your residency must be verified before you can request documents online.';
    end if;
    if v_waived then
      raise exception 'Fee waivers are granted by the Barangay Secretary.';
    end if;
    v_channel := 'online';
  else
    v_channel := 'walk_in';
    if v_res.verification_status <> 'verified' then
      -- The Secretary checks the resident's ID in person at the counter
      update public.residents
         set verification_status = 'verified', verified_by = auth.uid(), verified_at = now()
       where id = v_res.id;
      perform public.write_audit('verification_approved', 'residents', v_res.id::text,
                                 jsonb_build_object('method', 'walk_in'));
    end if;
  end if;

  select * into v_type from public.document_types where id = p_document_type_id and is_active;
  if not found then
    raise exception 'That document type is not available.';
  end if;
  if not exists (select 1 from public.purposes where id = p_purpose_id and is_active) then
    raise exception 'Choose a valid purpose.';
  end if;
  if p_copies is null or p_copies < 1 or p_copies > v_type.max_copies then
    raise exception 'You can request 1 to % copies of this document.', v_type.max_copies;
  end if;
  if p_purpose_details is not null and char_length(p_purpose_details) > 255 then
    raise exception 'Purpose details must be 255 characters or fewer.';
  end if;

  v_months := (extract(year from age(current_date, v_res.resident_since)) * 12
             + extract(month from age(current_date, v_res.resident_since)))::integer;
  if v_months < v_type.min_residency_months then
    raise exception '% requires at least % months of residency in the barangay.', v_type.name, v_type.min_residency_months;
  end if;

  if v_type.once_per_lifetime and exists (
       select 1 from public.document_requests
       where resident_id = v_res.id and document_type_id = v_type.id and status = 'released') then
    raise exception '% can only be issued once per resident.', v_type.name;
  end if;

  if exists (
       select 1 from public.document_requests
       where resident_id = v_res.id and document_type_id = v_type.id
         and status not in ('released', 'rejected', 'cancelled')) then
    raise exception 'There is already an open request for %. Track it in Requests.', v_type.name;
  end if;

  if jsonb_typeof(coalesce(p_extra, '{}'::jsonb)) <> 'object' then
    raise exception 'Invalid request details.';
  end if;
  if v_type.template_key = 'business'
     and (coalesce(trim(p_extra ->> 'business_name'), '') = '' or coalesce(trim(p_extra ->> 'business_address'), '') = '') then
    raise exception 'Business name and business address are required for this document.';
  end if;

  if jsonb_typeof(coalesce(p_attachments, '[]'::jsonb)) <> 'array' then
    raise exception 'Invalid attachments.';
  end if;
  for v_att in select a.value from jsonb_array_elements(coalesce(p_attachments, '[]'::jsonb)) as a(value) loop
    if coalesce(v_att ->> 'path', '') not like v_res.id::text || '/%' then
      raise exception 'Invalid attachment reference.';
    end if;
    if coalesce(v_att ->> 'mime', '') not in ('application/pdf', 'image/jpeg', 'image/png') then
      raise exception 'Attachments must be PDF, JPG or PNG files.';
    end if;
    if coalesce((v_att ->> 'size')::integer, 0) not between 1 and 2097152 then
      raise exception 'Each attachment must be 2 MB or smaller.';
    end if;
    if nullif(v_att ->> 'requirement_id', '') is not null and not exists (
         select 1 from public.document_type_requirements
         where document_type_id = v_type.id and requirement_id = (v_att ->> 'requirement_id')::smallint) then
      raise exception 'An attachment does not match this document''s requirements.';
    end if;
  end loop;

  if v_channel = 'online' then
    select string_agg(rq.name, ', ' order by dtr.sort_order) into v_missing
    from public.document_type_requirements dtr
    join public.requirements rq on rq.id = dtr.requirement_id
    where dtr.document_type_id = v_type.id and dtr.is_mandatory and rq.accepts_upload
      and not exists (
        select 1 from jsonb_array_elements(coalesce(p_attachments, '[]'::jsonb)) as a(value)
        where nullif(a.value ->> 'requirement_id', '')::smallint = rq.id);
    if v_missing is not null then
      raise exception 'Upload the required files: %.', v_missing;
    end if;
  end if;

  if v_waived then
    if p_waiver_reason is null or char_length(trim(p_waiver_reason)) not between 10 and 500 then
      raise exception 'Give a waiver reason of 10 to 500 characters.';
    end if;
    v_fee := 0;
  else
    v_fee := v_type.fee * p_copies;
  end if;

  insert into public.document_requests (
    resident_id, document_type_id, purpose_id, purpose_details, copies, extra_details,
    channel, fee_amount, fee_waived, waiver_reason, status, requested_by)
  values (
    v_res.id, v_type.id, p_purpose_id, nullif(trim(p_purpose_details), ''), p_copies,
    coalesce(p_extra, '{}'::jsonb), v_channel, v_fee, v_waived,
    case when v_waived then trim(p_waiver_reason) end, 'pending', auth.uid())
  returning id, control_no into v_id, v_ctrl;

  insert into public.request_attachments (request_id, requirement_id, file_path, original_name, mime_type, size_bytes, uploaded_by)
  select v_id,
         nullif(a.value ->> 'requirement_id', '')::smallint,
         a.value ->> 'path',
         left(a.value ->> 'name', 200),
         a.value ->> 'mime',
         (a.value ->> 'size')::integer,
         auth.uid()
  from jsonb_array_elements(coalesce(p_attachments, '[]'::jsonb)) as a(value);

  insert into public.request_status_history (request_id, from_status, to_status, remarks, changed_by)
  values (v_id, null, 'pending',
          case when v_channel = 'walk_in' then 'Encoded at the barangay hall' else 'Submitted online' end,
          auth.uid());

  if v_channel = 'online' then
    perform public.notify_profile(v_res.profile_id, v_id, 'Request received',
      format('Your request for %s was received. Your control number is %s.', v_type.name, v_ctrl));
    perform public.notify_role('secretary', v_id, 'New online request',
      format('%s %s requested %s (%s).', v_res.first_name, v_res.last_name, v_type.name, v_ctrl));
  else
    perform public.notify_profile(v_res.profile_id, v_id, 'Request recorded',
      format('Your walk-in request for %s was recorded under control number %s.', v_type.name, v_ctrl));
  end if;

  perform public.write_audit('create', 'document_requests', v_id::text,
                             jsonb_build_object('control_no', v_ctrl, 'channel', v_channel, 'document_type', v_type.code));
  return jsonb_build_object('id', v_id, 'control_no', v_ctrl);
end;
$$;

-- ---------------------------------------------------------------------
-- Attachments: re-upload and review
-- ---------------------------------------------------------------------
create or replace function public.add_request_attachment(p_request_id uuid, p_requirement_id integer, p_path text,
                                                         p_name text, p_mime text, p_size integer)
returns uuid
language plpgsql security definer
set search_path = ''
as $$
declare
  v_req public.document_requests%rowtype;
  v_res public.residents%rowtype;
  v_id  uuid;
begin
  select * into v_req from public.document_requests where id = p_request_id;
  if not found then
    raise exception 'Request not found.';
  end if;
  select * into v_res from public.residents where id = v_req.resident_id;
  if not ((public.has_role('resident') and v_res.profile_id = auth.uid()) or public.has_role('secretary')) then
    raise exception 'You cannot add files to this request.';
  end if;
  if v_req.status not in ('pending', 'under_review') then
    raise exception 'Files can only be added while the request is pending or under review.';
  end if;
  if coalesce(p_path, '') not like v_res.id::text || '/%' then
    raise exception 'Invalid file reference.';
  end if;
  if p_mime not in ('application/pdf', 'image/jpeg', 'image/png') or p_size is null or p_size not between 1 and 2097152 then
    raise exception 'Files must be PDF, JPG or PNG and 2 MB or smaller.';
  end if;
  if p_requirement_id is not null and not exists (
       select 1 from public.document_type_requirements
       where document_type_id = v_req.document_type_id and requirement_id = p_requirement_id) then
    raise exception 'That requirement does not apply to this document.';
  end if;

  insert into public.request_attachments (request_id, requirement_id, file_path, original_name, mime_type, size_bytes, uploaded_by)
  values (v_req.id, p_requirement_id, p_path, left(p_name, 200), p_mime, p_size, auth.uid())
  returning id into v_id;

  if public.has_role('resident') then
    perform public.notify_role('secretary', v_req.id, 'New file uploaded',
      format('A file was added to %s.', v_req.control_no));
  end if;
  perform public.write_audit('attachment_add', 'document_requests', v_req.id::text, jsonb_build_object('attachment_id', v_id));
  return v_id;
end;
$$;

create or replace function public.review_attachment(p_attachment_id uuid, p_decision text, p_remarks text default null)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_att public.request_attachments%rowtype;
  v_req public.document_requests%rowtype;
  v_res public.residents%rowtype;
  v_remarks text := nullif(trim(coalesce(p_remarks, '')), '');
  v_req_name text;
begin
  if not public.has_role('secretary') then
    raise exception 'Only the Barangay Secretary reviews attachments.';
  end if;
  if p_decision not in ('accepted', 'rejected') then
    raise exception 'Choose accept or reject.';
  end if;
  select * into v_att from public.request_attachments where id = p_attachment_id for update;
  if not found then
    raise exception 'Attachment not found.';
  end if;
  select * into v_req from public.document_requests where id = v_att.request_id;
  if v_req.status not in ('pending', 'under_review') then
    raise exception 'Attachments can only be reviewed while the request is pending or under review.';
  end if;
  if p_decision = 'rejected' and (v_remarks is null or char_length(v_remarks) not between 10 and 500) then
    raise exception 'Give a reason of 10 to 500 characters.';
  end if;

  update public.request_attachments
     set review_status = p_decision, review_remarks = v_remarks
   where id = v_att.id;

  if p_decision = 'rejected' then
    select * into v_res from public.residents where id = v_req.resident_id;
    select name into v_req_name from public.requirements where id = v_att.requirement_id;
    perform public.notify_profile(v_res.profile_id, v_req.id, 'Upload a replacement file',
      format('%s for %s was not accepted: %s', coalesce(v_req_name, 'A file'), v_req.control_no, v_remarks));
  end if;
  perform public.write_audit('attachment_' || p_decision, 'document_requests', v_req.id::text,
                             jsonb_build_object('attachment_id', v_att.id));
end;
$$;

-- ---------------------------------------------------------------------
-- The request state machine
-- ---------------------------------------------------------------------
create or replace function public.transition_request(p_request_id uuid, p_to_status text,
                                                     p_remarks text default null, p_released_to text default null)
returns jsonb
language plpgsql security definer
set search_path = ''
as $$
declare
  v_role    text := public.current_role_code();
  v_req     public.document_requests%rowtype;
  v_type    public.document_types%rowtype;
  v_res     public.residents%rowtype;
  v_from    text;
  v_ok      boolean := false;
  v_remarks text := nullif(trim(coalesce(p_remarks, '')), '');
  v_open    integer;
  v_title   text;
  v_msg     text;
begin
  if v_role is null then
    raise exception 'Sign in to continue.';
  end if;
  if p_to_status = 'ready_for_release' then
    raise exception 'Use Issue document to complete this step.';
  end if;

  select * into v_req from public.document_requests where id = p_request_id for update;
  if not found then
    raise exception 'Request not found.';
  end if;
  select * into v_type from public.document_types where id = v_req.document_type_id;
  select * into v_res from public.residents where id = v_req.resident_id;
  v_from := v_req.status;

  if v_from = 'pending' and p_to_status = 'under_review' and v_role = 'secretary' then
    v_ok := true;

  elsif v_from = 'under_review' and p_to_status in ('for_payment', 'processing') and v_role = 'secretary' then
    if v_req.channel = 'online' then
      select count(*) into v_open
      from public.document_type_requirements dtr
      join public.requirements rq on rq.id = dtr.requirement_id
      where dtr.document_type_id = v_req.document_type_id and dtr.is_mandatory and rq.accepts_upload
        and not exists (
          select 1 from public.request_attachments ra
          where ra.request_id = v_req.id and ra.requirement_id = rq.id and ra.review_status = 'accepted');
      if v_open > 0 then
        raise exception 'Accept every required file first (% still pending or rejected).', v_open;
      end if;
    end if;
    if p_to_status = 'for_payment' and v_req.fee_amount <= 0 then
      raise exception 'This request has no fee. Move it to Processing instead.';
    end if;
    if p_to_status = 'processing' and v_req.fee_amount > 0 then
      raise exception 'This request has a fee. Send it for payment first.';
    end if;
    v_ok := true;

  elsif v_from = 'processing' and p_to_status = 'for_approval' and v_role = 'secretary' then
    if not v_type.requires_captain_approval then
      raise exception 'This document does not need the captain''s approval. Issue it directly.';
    end if;
    v_ok := true;

  elsif v_from = 'ready_for_release' and p_to_status = 'released' and v_role in ('secretary', 'admin') then
    if p_released_to is null or char_length(trim(p_released_to)) not between 2 and 120 then
      raise exception 'Enter the name of the person who claimed the document.';
    end if;
    v_ok := true;

  elsif p_to_status = 'rejected'
        and ((v_from = 'under_review' and v_role = 'secretary') or (v_from = 'for_approval' and v_role = 'captain')) then
    if v_remarks is null or char_length(v_remarks) not between 10 and 500 then
      raise exception 'Give a reason of 10 to 500 characters.';
    end if;
    v_ok := true;

  elsif p_to_status = 'cancelled' and v_from in ('pending', 'for_payment')
        and ((v_role = 'resident' and v_res.profile_id = auth.uid())
             or (v_role = 'secretary' and v_req.channel = 'walk_in')) then
    v_ok := true;
  end if;

  if not v_ok then
    raise exception 'That status change is not allowed for your role at this stage.';
  end if;

  update public.document_requests
     set status = p_to_status,
         rejection_reason = case when p_to_status = 'rejected' then v_remarks else rejection_reason end,
         released_at = case when p_to_status = 'released' then now() else released_at end,
         released_to = case when p_to_status = 'released' then trim(p_released_to) else released_to end
   where id = v_req.id;

  insert into public.request_status_history (request_id, from_status, to_status, remarks, changed_by)
  values (v_req.id, v_from, p_to_status,
          case when p_to_status = 'released' then 'Claimed by ' || trim(p_released_to) else v_remarks end,
          auth.uid());

  v_title := case p_to_status
    when 'under_review' then 'Request under review'
    when 'for_payment'  then 'Payment needed'
    when 'processing'   then 'Request being processed'
    when 'for_approval' then 'Waiting for the captain''s approval'
    when 'released'     then 'Document released'
    when 'rejected'     then 'Request rejected'
    when 'cancelled'    then 'Request cancelled'
  end;
  v_msg := case p_to_status
    when 'for_payment' then format('Pay PHP %s at the Treasurer''s window for %s (%s).',
                                   to_char(v_req.fee_amount, 'FM999,990.00'), v_type.name, v_req.control_no)
    when 'rejected'    then format('%s (%s) was rejected: %s', v_type.name, v_req.control_no, v_remarks)
    when 'released'    then format('%s (%s) was released to %s.', v_type.name, v_req.control_no, trim(p_released_to))
    else format('%s (%s)', v_type.name, v_req.control_no)
  end;

  if v_role <> 'resident' then
    perform public.notify_profile(v_res.profile_id, v_req.id, v_title, v_msg);
  end if;
  if p_to_status = 'for_payment' then
    perform public.notify_role('treasurer', v_req.id, 'Awaiting payment',
      format('%s for %s %s (%s).', v_type.name, v_res.first_name, v_res.last_name, v_req.control_no));
  elsif p_to_status = 'for_approval' then
    perform public.notify_role('captain', v_req.id, 'Approval needed',
      format('%s for %s %s (%s).', v_type.name, v_res.first_name, v_res.last_name, v_req.control_no));
  end if;

  perform public.write_audit('status_change', 'document_requests', v_req.id::text,
    jsonb_build_object('control_no', v_req.control_no, 'from', v_from, 'to', p_to_status));
  return jsonb_build_object('id', v_req.id, 'status', p_to_status);
end;
$$;

-- ---------------------------------------------------------------------
-- Payments
-- ---------------------------------------------------------------------
create or replace function public.record_payment(p_request_id uuid, p_or_number text, p_amount numeric,
                                                 p_method text, p_reference_no text default null)
returns uuid
language plpgsql security definer
set search_path = ''
as $$
declare
  v_req  public.document_requests%rowtype;
  v_res  public.residents%rowtype;
  v_type public.document_types%rowtype;
  v_or   text := upper(trim(coalesce(p_or_number, '')));
  v_ref  text := nullif(trim(coalesce(p_reference_no, '')), '');
  v_id   uuid;
begin
  if not public.has_role('treasurer') then
    raise exception 'Only the Barangay Treasurer records payments.';
  end if;
  select * into v_req from public.document_requests where id = p_request_id for update;
  if not found then
    raise exception 'Request not found.';
  end if;
  if v_req.status <> 'for_payment' then
    raise exception 'This request is not waiting for payment.';
  end if;
  if v_or !~ '^[A-Z0-9-]{1,30}$' then
    raise exception 'Enter a valid OR number (letters, digits and hyphens, up to 30).';
  end if;
  if p_method not in ('cash', 'gcash', 'maya', 'bank_transfer') then
    raise exception 'Choose a valid payment method.';
  end if;
  if p_method <> 'cash' and v_ref is null then
    raise exception 'Enter the reference number for this payment.';
  end if;
  if p_amount is null or round(p_amount, 2) <> v_req.fee_amount then
    raise exception 'Amount must be exactly PHP %.', to_char(v_req.fee_amount, 'FM999,990.00');
  end if;
  if exists (select 1 from public.payments where upper(or_number) = v_or) then
    raise exception 'OR number % has already been used.', v_or;
  end if;

  select * into v_res from public.residents where id = v_req.resident_id;
  select * into v_type from public.document_types where id = v_req.document_type_id;

  insert into public.payments (request_id, or_number, amount, method, reference_no, received_by)
  values (v_req.id, v_or, v_req.fee_amount, p_method, case when p_method = 'cash' then null else v_ref end, auth.uid())
  returning id into v_id;

  update public.document_requests set status = 'processing' where id = v_req.id;
  insert into public.request_status_history (request_id, from_status, to_status, remarks, changed_by)
  values (v_req.id, 'for_payment', 'processing', 'Paid, OR ' || v_or, auth.uid());

  perform public.notify_profile(v_res.profile_id, v_req.id, 'Payment received',
    format('We received PHP %s for %s (%s). OR %s.', to_char(v_req.fee_amount, 'FM999,990.00'), v_type.name, v_req.control_no, v_or));
  perform public.notify_role('secretary', v_req.id, 'Paid and ready to process',
    format('%s (%s) is paid.', v_type.name, v_req.control_no));
  perform public.write_audit('payment_post', 'payments', v_id::text,
    jsonb_build_object('control_no', v_req.control_no, 'or_number', v_or, 'amount', v_req.fee_amount, 'method', p_method));
  return v_id;
end;
$$;

create or replace function public.void_payment(p_payment_id uuid, p_reason text)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_pay public.payments%rowtype;
  v_req public.document_requests%rowtype;
  v_res public.residents%rowtype;
  v_reason text := nullif(trim(coalesce(p_reason, '')), '');
begin
  if not public.has_role('treasurer') then
    raise exception 'Only the Barangay Treasurer voids payments.';
  end if;
  if v_reason is null or char_length(v_reason) not between 10 and 500 then
    raise exception 'Give a reason of 10 to 500 characters.';
  end if;
  select * into v_pay from public.payments where id = p_payment_id for update;
  if not found or v_pay.status <> 'posted' then
    raise exception 'Only posted payments can be voided.';
  end if;
  select * into v_req from public.document_requests where id = v_pay.request_id for update;
  if v_req.status <> 'processing' then
    raise exception 'A payment can only be voided while its request is still in Processing.';
  end if;

  update public.payments
     set status = 'voided', void_reason = v_reason, voided_by = auth.uid(), voided_at = now()
   where id = v_pay.id;
  update public.document_requests set status = 'for_payment' where id = v_req.id;
  insert into public.request_status_history (request_id, from_status, to_status, remarks, changed_by)
  values (v_req.id, 'processing', 'for_payment', 'Payment OR ' || v_pay.or_number || ' voided: ' || v_reason, auth.uid());

  select * into v_res from public.residents where id = v_req.resident_id;
  perform public.notify_profile(v_res.profile_id, v_req.id, 'Payment voided',
    format('The payment for %s was voided. Please settle it again at the Treasurer''s window.', v_req.control_no));
  perform public.write_audit('payment_void', 'payments', v_pay.id::text,
    jsonb_build_object('control_no', v_req.control_no, 'or_number', v_pay.or_number, 'reason', v_reason));
end;
$$;

-- ---------------------------------------------------------------------
-- Issue (Secretary for no-approval types; Punong Barangay approves the rest)
-- ---------------------------------------------------------------------
create or replace function public.issue_document(p_request_id uuid, p_remarks text default null)
returns jsonb
language plpgsql security definer
set search_path = ''
as $$
declare
  v_req      public.document_requests%rowtype;
  v_type     public.document_types%rowtype;
  v_res      public.residents%rowtype;
  v_sig      smallint;
  v_prepared uuid;
  v_code     text;
  v_no       text;
  v_issued   uuid;
  v_from     text;
  v_today    date := (now() at time zone 'Asia/Manila')::date;
begin
  select * into v_req from public.document_requests where id = p_request_id for update;
  if not found then
    raise exception 'Request not found.';
  end if;
  select * into v_type from public.document_types where id = v_req.document_type_id;
  v_from := v_req.status;

  if v_from = 'processing' then
    if not public.has_role('secretary') then
      raise exception 'Only the Barangay Secretary issues documents.';
    end if;
    if v_type.requires_captain_approval then
      raise exception 'Send this request for the captain''s approval first.';
    end if;
    v_prepared := auth.uid();
  elsif v_from = 'for_approval' then
    if not public.has_role('captain') then
      raise exception 'Only the Punong Barangay can approve this request.';
    end if;
    select h.changed_by into v_prepared
    from public.request_status_history h
    where h.request_id = v_req.id and h.to_status = 'for_approval'
    order by h.changed_at desc limit 1;
  else
    raise exception 'This request is not ready to be issued.';
  end if;

  select o.id into v_sig
  from public.barangay_officials o
  where o.position = 'punong_barangay' and o.is_active
  order by o.term_start desc limit 1;
  if v_sig is null then
    raise exception 'Add the active Punong Barangay under Officials before issuing documents.';
  end if;

  v_code := upper(substr(replace(gen_random_uuid()::text, '-', ''), 1, 10));
  v_no := v_type.code || '-' || to_char(now() at time zone 'Asia/Manila', 'YYYY') || '-'
          || lpad(nextval('public.issued_document_seq')::text, 5, '0');

  insert into public.issued_documents (request_id, document_no, verification_code, signatory_id, prepared_by, valid_until)
  values (v_req.id, v_no, v_code, v_sig, coalesce(v_prepared, auth.uid()),
          case when v_type.validity_days is not null then v_today + v_type.validity_days::integer end)
  returning id into v_issued;

  update public.document_requests set status = 'ready_for_release' where id = v_req.id;
  insert into public.request_status_history (request_id, from_status, to_status, remarks, changed_by)
  values (v_req.id, v_from, 'ready_for_release',
          coalesce(nullif(trim(coalesce(p_remarks, '')), ''),
                   case when v_from = 'for_approval' then 'Approved by the Punong Barangay' else 'Document issued' end),
          auth.uid());

  select * into v_res from public.residents where id = v_req.resident_id;
  perform public.notify_profile(v_res.profile_id, v_req.id, 'Ready for pickup',
    format('Your %s is ready. Bring a valid ID and control number %s to the barangay hall.', v_type.name, v_req.control_no));
  if v_from = 'for_approval' then
    perform public.notify_role('secretary', v_req.id, 'Approved and ready to print',
      format('%s (%s) was approved.', v_type.name, v_req.control_no));
  end if;
  perform public.write_audit(case when v_from = 'for_approval' then 'approve' else 'issue' end,
    'document_requests', v_req.id::text,
    jsonb_build_object('control_no', v_req.control_no, 'document_no', v_no));
  return jsonb_build_object('id', v_issued, 'document_no', v_no);
end;
$$;

create or replace function public.revoke_document(p_issued_id uuid, p_reason text)
returns void
language plpgsql security definer
set search_path = ''
as $$
declare
  v_doc public.issued_documents%rowtype;
  v_req public.document_requests%rowtype;
  v_res public.residents%rowtype;
  v_reason text := nullif(trim(coalesce(p_reason, '')), '');
begin
  if not public.has_role('secretary', 'captain') then
    raise exception 'Only the Secretary or the Punong Barangay can revoke documents.';
  end if;
  if v_reason is null or char_length(v_reason) not between 10 and 500 then
    raise exception 'Give a reason of 10 to 500 characters.';
  end if;
  select * into v_doc from public.issued_documents where id = p_issued_id for update;
  if not found or v_doc.status <> 'valid' then
    raise exception 'Only valid documents can be revoked.';
  end if;
  update public.issued_documents
     set status = 'revoked', revoked_reason = v_reason, revoked_at = now()
   where id = v_doc.id;
  select * into v_req from public.document_requests where id = v_doc.request_id;
  select * into v_res from public.residents where id = v_req.resident_id;
  perform public.notify_profile(v_res.profile_id, v_req.id, 'Document revoked',
    format('Document %s was revoked: %s', v_doc.document_no, v_reason));
  perform public.write_audit('revoke', 'issued_documents', v_doc.id::text,
    jsonb_build_object('document_no', v_doc.document_no, 'reason', v_reason));
end;
$$;

create or replace function public.record_print(p_issued_id uuid)
returns integer
language plpgsql security definer
set search_path = ''
as $$
declare
  v_count integer;
  v_no text;
begin
  if not public.has_role('secretary') then
    raise exception 'Only the Barangay Secretary prints official copies.';
  end if;
  update public.issued_documents
     set print_count = print_count + 1
   where id = p_issued_id and status = 'valid'
  returning print_count, document_no into v_count, v_no;
  if v_count is null then
    raise exception 'Only valid documents can be printed.';
  end if;
  perform public.write_audit('print', 'issued_documents', p_issued_id::text,
                             jsonb_build_object('document_no', v_no, 'print_count', v_count));
  return v_count;
end;
$$;

-- ---------------------------------------------------------------------
-- Public certificate check (the only function anonymous callers may run)
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
           'signatory', o.full_name)
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

-- ---------------------------------------------------------------------
-- Dashboard and reports (SECURITY INVOKER: results obey the caller's RLS)
-- ---------------------------------------------------------------------
create or replace function public.dashboard_summary()
returns jsonb
language plpgsql stable
set search_path = ''
as $$
declare
  v_local_now   timestamp   := now() at time zone 'Asia/Manila';
  v_today       date        := (now() at time zone 'Asia/Manila')::date;
  v_month_local timestamp   := date_trunc('month', now() at time zone 'Asia/Manila');
  v_month_start timestamptz := date_trunc('month', now() at time zone 'Asia/Manila') at time zone 'Asia/Manila';
  v_day_start   timestamptz := (now() at time zone 'Asia/Manila')::date::timestamp at time zone 'Asia/Manila';
  v_cards   jsonb;
  v_months  jsonb;
  v_types   jsonb;
  v_daily   jsonb;
begin
  select jsonb_build_object(
    'residents_verified',    (select count(*) from public.residents where verification_status = 'verified' and status = 'active'),
    'verifications_pending', (select count(*) from public.resident_verifications where status = 'pending'),
    'pending',           (select count(*) from public.document_requests where status = 'pending'),
    'under_review',      (select count(*) from public.document_requests where status = 'under_review'),
    'for_payment',       (select count(*) from public.document_requests where status = 'for_payment'),
    'processing',        (select count(*) from public.document_requests where status = 'processing'),
    'for_approval',      (select count(*) from public.document_requests where status = 'for_approval'),
    'ready_for_release', (select count(*) from public.document_requests where status = 'ready_for_release'),
    'open',              (select count(*) from public.document_requests where status not in ('released','rejected','cancelled')),
    'released_month',    (select count(*) from public.document_requests where status = 'released' and released_at >= v_month_start),
    'released_total',    (select count(*) from public.document_requests where status = 'released'),
    'overdue',           (select count(*) from public.document_requests d
                            join public.document_types t on t.id = d.document_type_id
                           where d.status in ('pending','under_review','processing','for_approval')
                             and d.submitted_at + make_interval(days => t.processing_days::integer) < now()),
    'rejected_files',    (select count(distinct a.request_id) from public.request_attachments a
                            join public.document_requests d on d.id = a.request_id
                           where a.review_status = 'rejected' and d.status in ('pending','under_review')
                             and not exists (select 1 from public.request_attachments b
                                              where b.request_id = a.request_id
                                                and b.requirement_id is not distinct from a.requirement_id
                                                and b.review_status in ('pending','accepted'))),
    'collections_today', (select coalesce(sum(amount), 0) from public.payments where status = 'posted' and paid_at >= v_day_start),
    'collections_month', (select coalesce(sum(amount), 0) from public.payments where status = 'posted' and paid_at >= v_month_start),
    'voided_month',      (select count(*) from public.payments where status = 'voided' and voided_at >= v_month_start)
  ) into v_cards;

  select coalesce(jsonb_agg(jsonb_build_object(
           'month', to_char(g.m, 'YYYY-MM'),
           'label', to_char(g.m, 'Mon YYYY'),
           'submitted', (select count(*) from public.document_requests d
                          where d.submitted_at >= (g.m at time zone 'Asia/Manila')
                            and d.submitted_at < ((g.m + interval '1 month') at time zone 'Asia/Manila')),
           'released',  (select count(*) from public.document_requests d
                          where d.status = 'released'
                            and d.released_at >= (g.m at time zone 'Asia/Manila')
                            and d.released_at < ((g.m + interval '1 month') at time zone 'Asia/Manila')),
           'rejected',  (select count(*) from public.request_status_history h
                          join public.document_requests d on d.id = h.request_id
                          where h.to_status = 'rejected'
                            and h.changed_at >= (g.m at time zone 'Asia/Manila')
                            and h.changed_at < ((g.m + interval '1 month') at time zone 'Asia/Manila'))
         ) order by g.m), '[]'::jsonb)
    into v_months
  from generate_series(v_month_local - interval '5 months', v_month_local, interval '1 month') as g(m);

  select coalesce(jsonb_agg(jsonb_build_object('name', t.name, 'code', t.code, 'total', x.c) order by x.c desc), '[]'::jsonb)
    into v_types
  from (select document_type_id, count(*) as c
          from public.document_requests
         where submitted_at >= v_month_start
         group by document_type_id) x
  join public.document_types t on t.id = x.document_type_id;

  select coalesce(jsonb_agg(jsonb_build_object(
           'day', to_char(g.d, 'YYYY-MM-DD'),
           'label', to_char(g.d, 'Mon DD'),
           'total', (select coalesce(sum(p.amount), 0) from public.payments p
                      where p.status = 'posted'
                        and p.paid_at >= (g.d at time zone 'Asia/Manila')
                        and p.paid_at < ((g.d + interval '1 day') at time zone 'Asia/Manila'))
         ) order by g.d), '[]'::jsonb)
    into v_daily
  from generate_series((v_today - 29)::timestamp, v_today::timestamp, interval '1 day') as g(d);

  return jsonb_build_object('cards', v_cards, 'by_month', v_months, 'by_type', v_types,
                            'daily_collections', v_daily, 'generated_at', v_local_now);
end;
$$;

create or replace function public.report_issuance_summary(p_from date, p_to date, p_type integer default null)
returns table (document_type text, issued bigint, released bigint, revoked bigint, fees numeric)
language sql stable
set search_path = ''
as $$
  select t.name,
         count(i.id),
         count(i.id) filter (where d.status = 'released'),
         count(i.id) filter (where i.status = 'revoked'),
         coalesce(sum(p.amount), 0)
  from public.issued_documents i
  join public.document_requests d on d.id = i.request_id
  join public.document_types t on t.id = d.document_type_id
  left join public.payments p on p.request_id = d.id and p.status = 'posted'
  where (i.issued_at at time zone 'Asia/Manila')::date between p_from and p_to
    and (p_type is null or t.id = p_type)
  group by t.name
  order by 2 desc, 1;
$$;

create or replace function public.report_processing_time(p_from date, p_to date, p_type integer default null)
returns table (document_type text, target_days smallint, released bigint, avg_days numeric, max_days numeric, overdue_open bigint)
language sql stable
set search_path = ''
as $$
  select t.name,
         t.processing_days,
         count(d.id) filter (where d.status = 'released'
                               and (d.released_at at time zone 'Asia/Manila')::date between p_from and p_to),
         round((avg(extract(epoch from (d.released_at - d.submitted_at)) / 86400)
                filter (where d.status = 'released'
                          and (d.released_at at time zone 'Asia/Manila')::date between p_from and p_to))::numeric, 1),
         round((max(extract(epoch from (d.released_at - d.submitted_at)) / 86400)
                filter (where d.status = 'released'
                          and (d.released_at at time zone 'Asia/Manila')::date between p_from and p_to))::numeric, 1),
         count(d.id) filter (where d.status in ('pending','under_review','processing','for_approval')
                               and d.submitted_at + make_interval(days => t.processing_days::integer) < now())
  from public.document_types t
  left join public.document_requests d on d.document_type_id = t.id
  where (p_type is null or t.id = p_type)
  group by t.id, t.name, t.processing_days
  order by t.name;
$$;

create or replace function public.report_residents_by_purok()
returns table (purok text, total bigint, verified bigint, voters bigint, with_account bigint)
language sql stable
set search_path = ''
as $$
  select p.name,
         count(r.id),
         count(r.id) filter (where r.verification_status = 'verified'),
         count(r.id) filter (where r.is_registered_voter),
         count(r.id) filter (where r.profile_id is not null)
  from public.puroks p
  left join public.residents r on r.purok_id = p.id and r.status = 'active'
  group by p.id, p.name
  order by p.name;
$$;

-- ---------------------------------------------------------------------
-- Execute grants. Supabase grants EXECUTE to anon/authenticated by default,
-- so internal helpers are revoked explicitly.
-- ---------------------------------------------------------------------
revoke execute on function public.write_audit(text, text, text, jsonb) from public, anon, authenticated;
revoke execute on function public.notify_profile(uuid, uuid, text, text) from public, anon, authenticated;
revoke execute on function public.notify_role(text, uuid, text, text) from public, anon, authenticated;
revoke execute on function public.handle_new_user() from public, anon, authenticated;
revoke execute on function public.guard_profile_update() from public, anon, authenticated;
revoke execute on function public.guard_resident_update() from public, anon, authenticated;
revoke execute on function public.audit_row_change() from public, anon, authenticated;
revoke execute on function public.set_request_control_no() from public, anon, authenticated;

revoke execute on function public.log_event(text, text, text, jsonb, text, text) from public, anon;
revoke execute on function public.submit_verification(integer, text, text, text) from public, anon;
revoke execute on function public.review_verification(uuid, text, text) from public, anon;
revoke execute on function public.submit_request(uuid, integer, integer, text, integer, jsonb, jsonb, boolean, text) from public, anon;
revoke execute on function public.add_request_attachment(uuid, integer, text, text, text, integer) from public, anon;
revoke execute on function public.review_attachment(uuid, text, text) from public, anon;
revoke execute on function public.transition_request(uuid, text, text, text) from public, anon;
revoke execute on function public.record_payment(uuid, text, numeric, text, text) from public, anon;
revoke execute on function public.void_payment(uuid, text) from public, anon;
revoke execute on function public.issue_document(uuid, text) from public, anon;
revoke execute on function public.revoke_document(uuid, text) from public, anon;
revoke execute on function public.record_print(uuid) from public, anon;
revoke execute on function public.dashboard_summary() from public, anon;
revoke execute on function public.report_issuance_summary(date, date, integer) from public, anon;
revoke execute on function public.report_processing_time(date, date, integer) from public, anon;
revoke execute on function public.report_residents_by_purok() from public, anon;

grant execute on function public.log_event(text, text, text, jsonb, text, text) to authenticated;
grant execute on function public.submit_verification(integer, text, text, text) to authenticated;
grant execute on function public.review_verification(uuid, text, text) to authenticated;
grant execute on function public.submit_request(uuid, integer, integer, text, integer, jsonb, jsonb, boolean, text) to authenticated;
grant execute on function public.add_request_attachment(uuid, integer, text, text, text, integer) to authenticated;
grant execute on function public.review_attachment(uuid, text, text) to authenticated;
grant execute on function public.transition_request(uuid, text, text, text) to authenticated;
grant execute on function public.record_payment(uuid, text, numeric, text, text) to authenticated;
grant execute on function public.void_payment(uuid, text) to authenticated;
grant execute on function public.issue_document(uuid, text) to authenticated;
grant execute on function public.revoke_document(uuid, text) to authenticated;
grant execute on function public.record_print(uuid) to authenticated;
grant execute on function public.dashboard_summary() to authenticated;
grant execute on function public.report_issuance_summary(date, date, integer) to authenticated;
grant execute on function public.report_processing_time(date, date, integer) to authenticated;
grant execute on function public.report_residents_by_purok() to authenticated;

grant execute on function public.current_role_code() to anon, authenticated;
grant execute on function public.has_role(text[]) to anon, authenticated;
grant execute on function public.is_staff() to anon, authenticated;
grant execute on function public.my_resident_id() to anon, authenticated;
grant execute on function public.verify_document(text) to anon, authenticated;
