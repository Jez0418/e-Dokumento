-- 14. Optional ID type and number on the resident record (entered by staff).
-- Safe to run more than once.
alter table public.residents
  add column if not exists id_type_id smallint references public.id_types (id),
  add column if not exists id_number  text;

do $$
begin
  if not exists (select 1 from pg_constraint where conname = 'residents_id_pair_chk') then
    alter table public.residents
      add constraint residents_id_pair_chk
      check ((id_type_id is null) = (id_number is null)
             and (id_number is null or char_length(id_number) between 3 and 40));
  end if;
end $$;
