-- =====================================================================
-- 05_seed.sql -- starting configuration (reference data only).
-- No residents, requests or payments are seeded: every transaction you see
-- in the app was entered through it. Fees below are placeholders: set them
-- to your barangay's revenue ordinance under Document types.
-- =====================================================================

insert into public.puroks (name, description) values
  ('Purok 1', null), ('Purok 2', null), ('Purok 3', null), ('Purok 4', null),
  ('Purok 5', null), ('Purok 6', null), ('Purok 7', null)
on conflict (name) do nothing;

insert into public.id_types (name) values
  ('PhilSys National ID'), ('Driver''s License'), ('Philippine Passport'), ('UMID'),
  ('SSS ID'), ('Postal ID'), ('Voter''s ID or Voter''s Certification'), ('PRC ID'),
  ('Senior Citizen ID'), ('PWD ID'), ('School ID with current registration')
on conflict (name) do nothing;

insert into public.purposes (name) values
  ('Employment'), ('Scholarship'), ('School requirement'), ('Bank or loan application'),
  ('Travel'), ('Medical or financial assistance'), ('Business permit application'),
  ('Government ID application'), ('Legal or court requirement'), ('Other')
on conflict (name) do nothing;

insert into public.requirements (name, description, accepts_upload) values
  ('Valid government-issued ID', 'A clear photo or scan of the front of any ID listed under accepted IDs.', true),
  ('Proof of residency', 'A recent utility bill, lease contract, or certification from your purok leader.', true),
  ('Community Tax Certificate (cedula)', 'Current-year cedula.', true),
  ('DTI or SEC business registration', 'Certificate of business name registration or SEC registration.', true),
  ('Proof of business location', 'Lease contract or land title for the business site.', true),
  ('Oath of Undertaking (RA 11261)', 'Signed in person at the barangay hall when you claim the certificate.', false),
  ('Personal appearance for interview', 'The Secretary or a kagawad confirms your circumstances in person.', false)
on conflict (name) do nothing;

insert into public.document_types
  (code, name, description, fee, processing_days, validity_days, requires_captain_approval,
   min_residency_months, once_per_lifetime, max_copies, template_key)
values
  ('BC',  'Barangay Clearance', 'Certifies good standing in the barangay, for employment, loans and other purposes.',
          50.00, 1, 180, true, 6, false, 3, 'clearance'),
  ('COR', 'Certificate of Residency', 'Certifies that you live in the barangay and since when.',
          30.00, 1, 180, false, 6, false, 3, 'residency'),
  ('COI', 'Certificate of Indigency', 'Certifies low-income status for medical, burial, legal or educational assistance.',
          0.00, 1, 90, true, 6, false, 2, 'indigency'),
  ('BBC', 'Barangay Business Clearance', 'Required before the city or municipality issues a business permit.',
          300.00, 3, 365, true, 0, false, 2, 'business'),
  ('FTJ', 'First Time Jobseeker Certification', 'Free certification under RA 11261 for first-time job applicants. Issued once.',
          0.00, 1, 365, true, 6, true, 1, 'jobseeker')
on conflict (code) do nothing;

insert into public.document_type_requirements (document_type_id, requirement_id, is_mandatory, sort_order)
select t.id, r.id, x.mandatory, x.sort_order
from (values
  ('BC',  'Valid government-issued ID',          true,  1),
  ('BC',  'Community Tax Certificate (cedula)',  false, 2),
  ('COR', 'Valid government-issued ID',          true,  1),
  ('COR', 'Proof of residency',                  true,  2),
  ('COI', 'Valid government-issued ID',          true,  1),
  ('COI', 'Personal appearance for interview',   true,  2),
  ('BBC', 'Valid government-issued ID',          true,  1),
  ('BBC', 'DTI or SEC business registration',    true,  2),
  ('BBC', 'Proof of business location',          true,  3),
  ('FTJ', 'Valid government-issued ID',          true,  1),
  ('FTJ', 'Oath of Undertaking (RA 11261)',      true,  2)
) as x(code, requirement, mandatory, sort_order)
join public.document_types t on t.code = x.code
join public.requirements r on r.name = x.requirement
on conflict (document_type_id, requirement_id) do nothing;

insert into public.system_settings (key, value, description) values
  ('barangay_name',    '"Barangay Name"',            'Barangay name as printed on certificates (without the word Barangay)'),
  ('city_municipality','"City or Municipality"',     'City or municipality'),
  ('province',         '"Province"',                 'Province'),
  ('region',           '"Region"',                   'Region'),
  ('hall_address',     '"Barangay Hall, Street"',    'Barangay hall address'),
  ('contact_number',   '""',                         'Public contact number'),
  ('contact_email',    '""',                         'Public contact email'),
  ('office_hours',     '"Monday to Friday, 8:00 AM to 5:00 PM"', 'Office hours shown to residents'),
  ('control_prefix',   '"REQ"',                      'Prefix for request control numbers (2-6 letters)')
on conflict (key) do nothing;
