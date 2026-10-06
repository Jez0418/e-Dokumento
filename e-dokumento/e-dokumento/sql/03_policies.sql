-- =====================================================================
-- 03_policies.sql -- Row Level Security for all 19 tables + list views.
-- Writes to workflow tables happen only through SECURITY DEFINER functions
-- in 02_functions.sql, so those tables get SELECT policies only and their
-- INSERT/UPDATE/DELETE privileges are revoked from client roles.
-- =====================================================================

alter table public.roles                      enable row level security;
alter table public.profiles                   enable row level security;
alter table public.puroks                     enable row level security;
alter table public.residents                  enable row level security;
alter table public.id_types                   enable row level security;
alter table public.resident_verifications     enable row level security;
alter table public.document_types             enable row level security;
alter table public.requirements               enable row level security;
alter table public.document_type_requirements enable row level security;
alter table public.purposes                   enable row level security;
alter table public.system_settings            enable row level security;
alter table public.document_requests          enable row level security;
alter table public.request_attachments        enable row level security;
alter table public.request_status_history     enable row level security;
alter table public.payments                   enable row level security;
alter table public.barangay_officials         enable row level security;
alter table public.issued_documents           enable row level security;
alter table public.notifications              enable row level security;
alter table public.audit_logs                 enable row level security;

-- ---------------------------------------------------------------------
-- Table privileges (defence in depth on top of RLS)
-- ---------------------------------------------------------------------
revoke insert, update, delete on
  public.roles, public.resident_verifications, public.document_requests, public.request_attachments,
  public.request_status_history, public.payments, public.issued_documents, public.audit_logs
from anon, authenticated;

revoke insert, update, delete on
  public.profiles, public.residents, public.puroks, public.id_types, public.document_types,
  public.requirements, public.document_type_requirements, public.purposes, public.system_settings,
  public.barangay_officials, public.notifications
from anon;

revoke select on
  public.profiles, public.residents, public.resident_verifications, public.document_requests,
  public.request_attachments, public.request_status_history, public.payments, public.issued_documents,
  public.notifications, public.audit_logs, public.barangay_officials, public.roles
from anon;

-- Residents may only flip is_read on their own notifications
revoke update on public.notifications from authenticated;
grant update (is_read) on public.notifications to authenticated;
revoke insert, delete on public.notifications from authenticated;
revoke insert, delete on public.profiles from authenticated;

-- Views
revoke all on public.request_list, public.payment_list, public.issued_document_list from anon;
grant select on public.request_list, public.payment_list, public.issued_document_list to authenticated;

-- ---------------------------------------------------------------------
-- roles
-- ---------------------------------------------------------------------
create policy "Signed-in users read roles"
  on public.roles for select to authenticated using (true);

-- ---------------------------------------------------------------------
-- profiles
-- ---------------------------------------------------------------------
create policy "Users read own profile; staff read all"
  on public.profiles for select to authenticated
  using (id = (select auth.uid()) or public.is_staff());

create policy "Users update own profile; admin updates any"
  on public.profiles for update to authenticated
  using (id = (select auth.uid()) or public.has_role('admin'))
  with check (id = (select auth.uid()) or public.has_role('admin'));

-- ---------------------------------------------------------------------
-- Reference data: puroks, id_types, purposes, requirements, document types
-- Public registration needs active puroks; everything else needs sign-in.
-- ---------------------------------------------------------------------
create policy "Anyone reads active puroks"
  on public.puroks for select to anon using (is_active);
create policy "Signed-in users read puroks"
  on public.puroks for select to authenticated using (true);
create policy "Admin inserts puroks"
  on public.puroks for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates puroks"
  on public.puroks for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes puroks"
  on public.puroks for delete to authenticated using (public.has_role('admin'));

create policy "Signed-in users read ID types"
  on public.id_types for select to authenticated using (true);
create policy "Admin inserts ID types"
  on public.id_types for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates ID types"
  on public.id_types for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes ID types"
  on public.id_types for delete to authenticated using (public.has_role('admin'));

create policy "Signed-in users read purposes"
  on public.purposes for select to authenticated using (true);
create policy "Admin inserts purposes"
  on public.purposes for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates purposes"
  on public.purposes for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes purposes"
  on public.purposes for delete to authenticated using (public.has_role('admin'));

create policy "Signed-in users read requirements"
  on public.requirements for select to authenticated using (true);
create policy "Admin inserts requirements"
  on public.requirements for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates requirements"
  on public.requirements for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes requirements"
  on public.requirements for delete to authenticated using (public.has_role('admin'));

-- All signed-in users read every type (old requests keep showing a type even after it is
-- deactivated); request forms list only active ones, and submit_request rejects inactive types.
create policy "Signed-in users read document types"
  on public.document_types for select to authenticated using (true);
create policy "Admin inserts document types"
  on public.document_types for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates document types"
  on public.document_types for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes document types"
  on public.document_types for delete to authenticated using (public.has_role('admin'));

create policy "Signed-in users read document requirements"
  on public.document_type_requirements for select to authenticated using (true);
create policy "Admin inserts document requirements"
  on public.document_type_requirements for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates document requirements"
  on public.document_type_requirements for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes document requirements"
  on public.document_type_requirements for delete to authenticated using (public.has_role('admin'));

-- ---------------------------------------------------------------------
-- system_settings: public read (barangay name on the login page), admin write
-- ---------------------------------------------------------------------
create policy "Anyone reads settings"
  on public.system_settings for select to anon, authenticated using (true);
create policy "Admin inserts settings"
  on public.system_settings for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates settings"
  on public.system_settings for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));

-- ---------------------------------------------------------------------
-- barangay_officials: signatories appear on certificates residents view
-- ---------------------------------------------------------------------
create policy "Signed-in users read officials"
  on public.barangay_officials for select to authenticated using (true);
create policy "Admin inserts officials"
  on public.barangay_officials for insert to authenticated with check (public.has_role('admin'));
create policy "Admin updates officials"
  on public.barangay_officials for update to authenticated using (public.has_role('admin')) with check (public.has_role('admin'));
create policy "Admin deletes officials"
  on public.barangay_officials for delete to authenticated using (public.has_role('admin'));

-- ---------------------------------------------------------------------
-- residents: own row, or staff. Secretary encodes and edits; residents edit
-- their own unverified record (column guard trigger enforces the rest).
-- ---------------------------------------------------------------------
create policy "Residents read own record; staff read all"
  on public.residents for select to authenticated
  using (profile_id = (select auth.uid()) or public.is_staff());

create policy "Secretary encodes residents"
  on public.residents for insert to authenticated
  with check (public.has_role('secretary'));

create policy "Secretary or the resident updates the record"
  on public.residents for update to authenticated
  using (public.has_role('secretary') or profile_id = (select auth.uid()))
  with check (public.has_role('secretary') or profile_id = (select auth.uid()));

-- ---------------------------------------------------------------------
-- resident_verifications (writes via submit_/review_verification)
-- ---------------------------------------------------------------------
create policy "Residents read own submissions; records staff read all"
  on public.resident_verifications for select to authenticated
  using (resident_id = public.my_resident_id() or public.has_role('admin', 'captain', 'secretary'));

-- ---------------------------------------------------------------------
-- document_requests and children (writes via workflow functions)
-- ---------------------------------------------------------------------
create policy "Residents read own requests; staff read all"
  on public.document_requests for select to authenticated
  using (resident_id = public.my_resident_id() or public.is_staff());

create policy "Attachments follow their request"
  on public.request_attachments for select to authenticated
  using (
    public.has_role('admin', 'captain', 'secretary')
    or exists (select 1 from public.document_requests d
               where d.id = request_id and d.resident_id = public.my_resident_id()));

create policy "History follows its request"
  on public.request_status_history for select to authenticated
  using (
    public.is_staff()
    or exists (select 1 from public.document_requests d
               where d.id = request_id and d.resident_id = public.my_resident_id()));

create policy "Payments follow their request"
  on public.payments for select to authenticated
  using (
    public.is_staff()
    or exists (select 1 from public.document_requests d
               where d.id = request_id and d.resident_id = public.my_resident_id()));

create policy "Issued documents follow their request"
  on public.issued_documents for select to authenticated
  using (
    public.is_staff()
    or exists (select 1 from public.document_requests d
               where d.id = request_id and d.resident_id = public.my_resident_id()));

-- ---------------------------------------------------------------------
-- notifications: own only; only is_read is updatable (column grant above)
-- ---------------------------------------------------------------------
create policy "Users read own notifications"
  on public.notifications for select to authenticated
  using (recipient_id = (select auth.uid()));

create policy "Users mark own notifications read"
  on public.notifications for update to authenticated
  using (recipient_id = (select auth.uid()))
  with check (recipient_id = (select auth.uid()));

-- ---------------------------------------------------------------------
-- audit_logs: read by Administrator and Punong Barangay; never updated or deleted
-- ---------------------------------------------------------------------
create policy "Admin and captain read audit logs"
  on public.audit_logs for select to authenticated
  using (public.has_role('admin', 'captain'));
