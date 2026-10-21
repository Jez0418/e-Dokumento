-- 17. Lock down EXECUTE on functions the API does not need.
-- Supabase publishes every public function at /rest/v1/rpc/<name>.
--   Trigger functions need no EXECUTE grant to fire, so these lose it.
--   RLS policies run as the signed-in caller, so the helpers keep EXECUTE for authenticated.
--   No anon policy calls a helper, so anon loses them.
--   verify_document stays callable by anon: it backs the public /verify page.
-- Safe to run more than once.

-- Trigger-only functions: nothing calls these over the API.
revoke execute on function public.guard_own_role() from public, anon, authenticated;
revoke execute on function public.guard_signature_path() from public, anon, authenticated;
revoke execute on function public.snapshot_signature() from public, anon, authenticated;
revoke execute on function public.sync_resident_to_profile() from public, anon, authenticated;
revoke execute on function public.sync_profile_to_resident() from public, anon, authenticated;

-- RLS helpers. PUBLIC holds EXECUTE by default, so revoke from PUBLIC and grant back to authenticated.
revoke execute on function public.current_role_code() from public, anon;
revoke execute on function public.has_role(text[]) from public, anon;
revoke execute on function public.is_staff() from public, anon;
revoke execute on function public.my_resident_id() from public, anon;
grant execute on function public.current_role_code() to authenticated;
grant execute on function public.has_role(text[]) to authenticated;
grant execute on function public.is_staff() to authenticated;
grant execute on function public.my_resident_id() to authenticated;
