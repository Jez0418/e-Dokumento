-- Temporary switch for the ID-verification requirement (testing).
-- Run in the Supabase SQL Editor.
--
--   Turn the requirement OFF:  update public.system_settings set value = '"off"' where key = 'require_id_verification';
--   Turn it back ON:           update public.system_settings set value = '"on"'  where key = 'require_id_verification';
--
-- With the setting missing or 'on', behaviour is unchanged.

insert into public.system_settings (key, value, description) values
  ('require_id_verification', '"off"', 'Residents must have an approved ID before requesting documents online: on or off')
on conflict (key) do nothing;

-- Patch submit_request so the resident branch honours the setting.
do $mig$
declare
  d text := pg_get_functiondef('public.submit_request(uuid,integer,integer,text,integer,jsonb,jsonb,boolean,text)'::regprocedure);
  pat text := 'if v_res\.verification_status <> ''verified'' then(\r?\n\s*raise exception ''Your residency must be verified)';
  rep text := 'if v_res.verification_status <> ''verified'' and coalesce((select s.value #>> ''{}'' from public.system_settings s where s.key = ''require_id_verification''), ''on'') <> ''off'' then\1';
begin
  if d !~ pat then
    raise exception 'submit_request: expected verification check not found (already patched?)';
  end if;
  execute regexp_replace(d, pat, rep);
end
$mig$;
