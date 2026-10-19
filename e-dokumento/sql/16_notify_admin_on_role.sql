-- 16. Admin monitors the working system: whenever secretary, treasurer or captain
--   gets a role notification, admin gets a copy of it too.
-- Safe to run more than once.

create or replace function public.notify_role(p_role text, p_request uuid, p_title text, p_message text)
returns void
language sql security definer
set search_path = ''
as $$
  insert into public.notifications (recipient_id, request_id, title, message)
  select p.id, p_request, left(p_title, 120), p_message
  from public.profiles p
  join public.roles r on r.id = p.role_id
  where p.status = 'active'
    and (r.code = p_role or (r.code = 'admin' and p_role in ('secretary', 'treasurer', 'captain')));
$$;
