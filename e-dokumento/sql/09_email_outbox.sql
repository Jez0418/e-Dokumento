-- =====================================================================
-- 09_email_outbox.sql -- status emails to residents (optional).
-- Run after 01-08. Safe to rerun. The switch is off by default: turn it on
-- under Settings -> Email notifications once Gmail is configured.
--
-- A trigger queues one email when a request becomes For payment, Ready for
-- release or Rejected; PHP sends queued emails right after a staff action.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Outbox
-- ---------------------------------------------------------------------
create table if not exists public.email_outbox (
  id          uuid primary key default gen_random_uuid(),
  request_id  uuid not null references public.document_requests (id) on delete cascade,
  to_email    text not null,
  kind        text not null check (kind in ('for_payment','ready_for_release','rejected')),
  subject     text not null check (char_length(subject) <= 150),
  body        text not null,
  status      text not null default 'queued' check (status in ('queued','sending','sent','failed')),
  attempts    smallint not null default 0,
  last_error  text check (last_error is null or char_length(last_error) <= 500),
  claimed_at  timestamptz,
  created_at  timestamptz not null default now(),
  sent_at     timestamptz
);
create index if not exists email_outbox_status_idx on public.email_outbox (status, created_at);

-- Only the Administrator reads it; only the security definer functions write it.
alter table public.email_outbox enable row level security;
revoke all on public.email_outbox from anon, authenticated;
grant select on public.email_outbox to authenticated;
drop policy if exists email_outbox_select_admin on public.email_outbox;
create policy email_outbox_select_admin
  on public.email_outbox for select to authenticated using (public.has_role('admin'));

insert into public.system_settings (key, value, description) values
  ('email_notifications_enabled', 'false', 'Email residents when a request needs them to act: true or false')
on conflict (key) do nothing;

-- ---------------------------------------------------------------------
-- Queueing trigger
-- ---------------------------------------------------------------------
create or replace function public.queue_status_email()
returns trigger
language plpgsql security definer
set search_path = ''
as $$
declare
  v_first   text;
  v_email   text;
  v_type    text;
  v_brgy    text;
  v_hours   text;
  v_hall    text;
  v_fee     text := to_char(new.fee_amount, 'FM999,990.00');
  v_subject text;
  v_lines   text[];
begin
  if not coalesce((select s.value from public.system_settings s where s.key = 'email_notifications_enabled') = 'true'::jsonb, false) then
    return null;
  end if;

  select r.first_name, r.email into v_first, v_email from public.residents r where r.id = new.resident_id;
  if v_email is null then
    return null;
  end if;
  select t.name into v_type from public.document_types t where t.id = new.document_type_id;
  select nullif(s.value #>> '{}', '') into v_brgy from public.system_settings s where s.key = 'barangay_name';
  select nullif(s.value #>> '{}', '') into v_hours from public.system_settings s where s.key = 'office_hours';
  select nullif(s.value #>> '{}', '') into v_hall from public.system_settings s where s.key = 'hall_address';

  -- array_to_string skips NULL elements, which leaves out lines whose value is empty.
  case new.status
    when 'for_payment' then
      v_subject := format('%s: Pay PHP %s for your %s', new.control_no, v_fee, v_type);
      v_lines := array[
        format('Your request for %s (%s) is ready for payment.', v_type, new.control_no),
        format('Please pay PHP %s at the Treasurer''s window of the barangay hall.', v_fee),
        'Office hours: ' || v_hours];
    when 'ready_for_release' then
      v_subject := format('%s: Your %s is ready for pickup', new.control_no, v_type);
      v_lines := array[
        format('Your %s (%s) is ready.', v_type, new.control_no),
        'Pick it up at the barangay hall: ' || v_hall,
        'Office hours: ' || v_hours,
        'Please bring a valid ID.'];
    when 'rejected' then
      v_subject := format('%s: Your %s request was not approved', new.control_no, v_type);
      v_lines := array[
        format('Your request for %s (%s) was not approved.', v_type, new.control_no),
        'Reason: ' || nullif(trim(coalesce(new.rejection_reason, '')), ''),
        'You may file a new request after addressing the reason above.'];
    else
      return null;
  end case;

  insert into public.email_outbox (request_id, to_email, kind, subject, body)
  values (new.id, v_email, new.status, left(v_subject, 150),
          array_to_string(
            array['Good day, ' || v_first || ',', '']
            || v_lines
            || array['', 'Barangay ' || v_brgy,
                     'This is an automatic message from e-Dokumento. Replies to this email are not read.'],
            E'\n'));
  return null;
end;
$$;
revoke execute on function public.queue_status_email() from public, anon, authenticated;

drop trigger if exists document_requests_email on public.document_requests;
create trigger document_requests_email
  after update of status on public.document_requests
  for each row
  when (old.status is distinct from new.status and new.status in ('for_payment','ready_for_release','rejected'))
  execute function public.queue_status_email();
