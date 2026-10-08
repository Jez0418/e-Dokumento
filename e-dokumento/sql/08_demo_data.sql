-- =====================================================================
-- 08_demo_data.sql -- sample residents and requests for demonstrations.
--
-- Run once in the SQL Editor after 01-07. It needs the test Secretary,
-- Treasurer and Punong Barangay accounts from 07_test_accounts.sql and an
-- active Punong Barangay under Officials.
--
-- Every request goes through the real workflow functions (submit_request,
-- transition_request, record_payment, issue_document, revoke_document)
-- acting as those test accounts, so control numbers, status history,
-- payments, certificates, notifications and audit entries are what the app
-- itself would write. Timestamps are then spread over the last six months
-- so the dashboard, reports and /verify have something to show.
--
-- Demo residents are marked by an email ending in @demo.e-dokumento.test.
-- Remove the demo data with the block at the bottom of this file.
-- =====================================================================

-- The whole script runs in one transaction, so now() is the same instant for
-- every row the workflow writes. demo_stamp moves the rows still carrying
-- that instant to the simulated time of the step that wrote them.

create or replace function pg_temp.demo_act(p_user uuid)
returns void
language sql
as $$
  select set_config('request.jwt.claims',
                    json_build_object('sub', p_user, 'role', 'authenticated')::text, true);
$$;

create or replace function pg_temp.demo_stamp(p_at timestamptz)
returns void
language sql
as $$
  update public.request_status_history set changed_at = p_at where changed_at = now();
  update public.notifications
     set created_at = p_at, is_read = p_at < now() - interval '3 days'
   where created_at = now();
  update public.audit_logs set created_at = p_at where created_at = now();
$$;

-- Office hours on the day p_days ago (Manila), never later than three hours ago
create or replace function pg_temp.demo_start(p_days integer)
returns timestamptz
language sql volatile
as $$
  select least(
    ((((now() at time zone 'Asia/Manila')::date - p_days)::timestamp
       + interval '8 hours' + random() * interval '7 hours') at time zone 'Asia/Manila'),
    now() - interval '3 hours');
$$;

-- Next step: 2 to 24 hours later, shrinking near the present so it stays in the past
create or replace function pg_temp.demo_next(p_at timestamptz)
returns timestamptz
language sql volatile
as $$
  select p_at + least(interval '2 hours' + random() * interval '22 hours',
                      greatest((now() - interval '5 minutes' - p_at) / 4, interval '1 minute'));
$$;

-- Squeeze a week into its office hours (Monday to Friday, 8:00 AM to 5:00 PM,
-- Manila). Order is kept, so each request's steps stay in sequence.
create or replace function pg_temp.demo_office(p_at timestamptz)
returns timestamptz
language sql immutable strict
as $$
  select (w.monday + make_interval(days => floor(w.b / 32400)::integer) + interval '8 hours'
          + make_interval(secs => w.b - floor(w.b / 32400) * 32400)) at time zone 'Asia/Manila'
  from (select date_trunc('week', p_at at time zone 'Asia/Manila') as monday,
               extract(epoch from (p_at at time zone 'Asia/Manila')
                                  - date_trunc('week', p_at at time zone 'Asia/Manila')) * 45 / 168 as b) w;
$$;

do $demo$
declare
  v_sec    uuid;
  v_tre    uuid;
  v_cap    uuid;
  v_puroks smallint[];
  v_res    uuid[] := '{}';
  r        record;
  s        record;
  v_type   public.document_types%rowtype;
  v_fee    numeric(10,2);
  v_out    jsonb;
  v_id     uuid;
  v_issued uuid;
  v_pay    uuid;
  v_t      timestamptz;
  v_or     integer := 1001201;
  v_or_txt text;
  v_name   text;
begin
  select id into v_sec from public.profiles where email = 'secretary.test@example.test' and status = 'active';
  select id into v_tre from public.profiles where email = 'treasurer.test@example.test' and status = 'active';
  select id into v_cap from public.profiles where email = 'captain.test@example.test' and status = 'active';
  if v_sec is null or v_tre is null or v_cap is null then
    raise exception 'Run 07_test_accounts.sql first: the test Secretary, Treasurer and Punong Barangay accounts are needed.';
  end if;
  if not exists (select 1 from public.barangay_officials where position = 'punong_barangay' and is_active) then
    raise exception 'Add the active Punong Barangay under Officials first.';
  end if;
  if exists (select 1 from public.residents where email like '%@demo.e-dokumento.test') then
    raise exception 'Demo data is already loaded. Remove it with the block at the bottom of this file first.';
  end if;

  select array_agg(id order by id) into v_puroks from public.puroks where is_active;

  -- Residents, encoded by the Secretary about six months ago -----------------
  perform pg_temp.demo_act(v_sec);
  for r in
    select * from (values
      ( 1, 'Maria',         'Lopez',       'Dela Cruz',  null,  'female', '1988-03-12', 'married',   '12 Mabini Street',          '2010-06-01', true,  'Public school teacher',  '09171230001', true),
      ( 2, 'Jose',          'Mendoza',     'Reyes',      'Jr.', 'male',   '1979-11-02', 'married',   '45 Rizal Avenue',           '1979-11-02', true,  'Tricycle driver',        '09171230002', true),
      ( 3, 'Angelica',      'Bautista',    'Garcia',     null,  'female', '2003-07-21', 'single',    '7 Sampaguita Lane',         '2003-07-21', true,  'Fresh graduate',         '09171230003', true),
      ( 4, 'Mark Anthony',  'Villanueva',  'Ramos',      null,  'male',   '2002-01-15', 'single',    '19 Narra Street',           '2008-04-10', true,  'Fresh graduate',         '09171230004', true),
      ( 5, 'Rosario',       'Castillo',    'Aquino',     null,  'female', '1955-09-30', 'widowed',   '3 Kalayaan Street',         '1975-02-14', true,  null,                     '09171230005', true),
      ( 6, 'Eduardo',       'Pascual',     'Navarro',    null,  'male',   '1968-05-05', 'married',   '88 Bonifacio Street',       '1995-09-01', true,  'Store owner',            '09171230006', true),
      ( 7, 'Kristine Joy',  'Morales',     'Flores',     null,  'female', '1996-12-08', 'single',    '22 Ilang-Ilang Street',     '1996-12-08', true,  'Call center agent',      '09171230007', true),
      ( 8, 'Ramon',         'Torres',      'Gonzales',   null,  'male',   '1984-02-27', 'married',   '5 Acacia Drive',            '2012-03-01', true,  'Overseas worker',        '09171230008', true),
      ( 9, 'Liza',          'Domingo',     'Mercado',    null,  'female', '1991-08-19', 'separated', '31 Mahogany Street',        '2015-07-15', true,  'Sales clerk',            '09171230009', true),
      (10, 'Paolo',         'Santiago',    'Cruz',       null,  'male',   '2004-04-02', 'single',    '14 Molave Street',          '2004-04-02', false, 'Student',                '09171230010', true),
      (11, 'Teresita',      'Ocampo',      'Valdez',     null,  'female', '1962-10-11', 'married',   '60 Del Pilar Street',       '1985-01-20', true,  'Eatery owner',           '09171230011', true),
      (12, 'Danilo',        'Fernandez',   'Salazar',    null,  'male',   '1975-06-23', 'married',   '9 Luna Street',             '2001-11-05', true,  'Construction foreman',   '09171230012', true),
      (13, 'Jasmine',       'Aguilar',     'Tolentino',  null,  'female', '2001-03-30', 'single',    '27 Rosal Street',           '2001-03-30', true,  'Nursing student',        '09171230013', true),
      (14, 'Ricardo',       'Manalo',      'Dizon',      null,  'male',   '1958-12-01', 'widowed',   '2 Kamagong Street',         '1980-06-30', true,  'Retired',                '09171230014', true),
      (15, 'Michelle Ann',  'Rivera',      'Soriano',    null,  'female', '1993-05-17', 'married',   '16 Jasmine Street',         '2018-02-01', true,  'Bank teller',            '09171230015', true),
      (16, 'Carlo',         'De Guzman',   'Pineda',     null,  'male',   '1999-09-09', 'single',    '40 Yakal Street',           '1999-09-09', true,  'Delivery rider',         '09171230016', true),
      (17, 'Lourdes',       'Bernardo',    'Javier',     null,  'female', '1970-01-25', 'married',   '11 Dahlia Street',          '1992-08-15', true,  'Barangay health worker', '09171230017', true),
      (18, 'Noel',          'Panganiban',  'Castro',     null,  'male',   '1987-07-07', 'married',   '73 Quezon Street',          '2011-05-10', true,  'Water station owner',    '09171230018', true),
      (19, 'Rhea Mae',      'Gutierrez',   'Lim',        null,  'female', '2000-11-11', 'single',    '6 Orchid Street',           '2000-11-11', true,  'Office clerk',           '09171230019', true),
      (20, 'Ernesto',       'Sison',       'Valencia',   null,  'male',   '1966-03-03', 'married',   '55 Aguinaldo Street',       '1990-10-01', true,  'Auto mechanic',          '09171230020', true),
      (21, 'Gloria',        'Medina',      'Robles',     null,  'female', '1949-08-14', 'widowed',   '18 Camia Street',           '1970-04-01', true,  null,                     '09171230021', true),
      (22, 'Arnel',         'Cabrera',     'Padilla',    null,  'male',   '1990-10-28', 'single',    '29 Banaba Street',          '2016-01-15', true,  'Security guard',         '09171230022', true),
      (23, 'Josefina',      'Marquez',     'Velasco',    null,  'female', '1983-04-16', 'annulled',  '8 Gumamela Street',         '2020-09-01', false, 'Seamstress',             '09171230023', false),
      (24, 'Kenneth',       'Uy',          'Tan',        null,  'male',   '1998-06-06', 'single',    '35 Lanzones Street',        '2022-03-15', false, 'Online seller',          '09171230024', false)
    ) as t(n, first_name, middle_name, last_name, suffix, sex, birth_date, civil_status, street,
           since, voter, occupation, mobile, verified)
    order by n
  loop
    v_t := pg_temp.demo_start(170 + r.n);
    insert into public.residents (
      purok_id, first_name, middle_name, last_name, suffix, birth_date, sex, civil_status,
      street_address, contact_no, email, occupation, resident_since, is_registered_voter,
      verification_status, verified_by, verified_at, created_by, created_at)
    values (
      v_puroks[1 + (r.n - 1) % array_length(v_puroks, 1)],
      r.first_name, r.middle_name, r.last_name, r.suffix, r.birth_date::date, r.sex, r.civil_status,
      r.street, r.mobile,
      lower(replace(r.first_name, ' ', '')) || '.' || lower(replace(r.last_name, ' ', '')) || '@demo.e-dokumento.test',
      r.occupation, r.since::date, r.voter,
      case when r.verified then 'verified' else 'unverified' end,
      case when r.verified then v_sec end,
      case when r.verified then v_t + interval '15 minutes' end,
      v_sec, v_t)
    returning id into v_id;
    v_res := v_res || v_id;
    perform pg_temp.demo_stamp(v_t);
  end loop;

  -- Requests, oldest first, each walked to its final status ------------------
  -- final: pending, cancelled, under_review, rejected, for_payment, processing,
  --        for_approval, rejected_captain, ready_for_release, released, revoked
  for s in
    select * from (values
      ( 1,  1, 'BC',  'Employment',                       1, 'released',          158, 'cash',  false),
      ( 2,  2, 'COR', 'Bank or loan application',         1, 'released',          151, 'cash',  false),
      ( 3,  5, 'COI', 'Medical or financial assistance',  1, 'released',          146, 'cash',  false),
      ( 4,  6, 'BBC', 'Business permit application',      1, 'released',          139, 'cash',  false),
      ( 5,  3, 'FTJ', 'Employment',                       1, 'released',          127, 'cash',  false),
      ( 6,  8, 'BC',  'Travel',                           2, 'released',          121, 'cash',  false),
      ( 7,  7, 'COR', 'Scholarship',                      1, 'released',          114, 'gcash', false),
      ( 8, 11, 'BBC', 'Business permit application',      1, 'released',          108, 'maya',  false),
      ( 9, 14, 'COI', 'Medical or financial assistance',  1, 'released',          101, 'cash',  false),
      (10,  9, 'BC',  'Legal or court requirement',       1, 'rejected',           96, 'cash',  false),
      (11, 12, 'BC',  'Employment',                       1, 'released',           88, 'gcash', false),
      (12, 13, 'COR', 'School requirement',               1, 'released',           81, 'cash',  false),
      (13,  4, 'FTJ', 'Employment',                       1, 'released',           74, 'cash',  false),
      (14, 15, 'BC',  'Bank or loan application',         1, 'released',           67, 'maya',  false),
      (15, 17, 'COR', 'Government ID application',        1, 'released',           60, 'cash',  false),
      (16, 16, 'BC',  'Employment',                       1, 'revoked',            53, 'cash',  false),
      (17, 18, 'BC',  'Travel',                           1, 'released',           46, 'cash',  false),
      (18, 21, 'BC',  'Medical or financial assistance',  1, 'released',           40, 'cash',  true),
      (19, 20, 'BBC', 'Business permit application',      1, 'released',           35, 'cash',  false),
      (20, 19, 'COR', 'Scholarship',                      1, 'cancelled',          31, 'cash',  false),
      (21, 22, 'BC',  'Employment',                       1, 'released',           27, 'gcash', false),
      (22,  1, 'COR', 'Bank or loan application',         1, 'released',           23, 'cash',  false),
      (23, 10, 'FTJ', 'Employment',                       1, 'released',           19, 'cash',  false),
      (24,  2, 'BC',  'Employment',                       1, 'released',           16, 'cash',  false),
      (25,  5, 'COI', 'Medical or financial assistance',  1, 'released',           13, 'cash',  false),
      (26, 13, 'BC',  'Scholarship',                      1, 'rejected_captain',   11, 'cash',  false),
      (27,  7, 'BC',  'Employment',                       1, 'released',            9, 'maya',  false),
      (28,  8, 'COR', 'Travel',                           1, 'released',            7, 'gcash', false),
      (29, 10, 'BC',  'Employment',                       1, 'under_review',        6, 'cash',  false),
      (30, 15, 'COI', 'Medical or financial assistance',  1, 'released',            5, 'cash',  false),
      (31,  9, 'COR', 'Bank or loan application',         1, 'ready_for_release',   4, 'cash',  false),
      (32, 12, 'COI', 'Medical or financial assistance',  1, 'ready_for_release',   3, 'cash',  false),
      (33, 11, 'BC',  'Bank or loan application',         1, 'ready_for_release',   3, 'gcash', false),
      (34,  6, 'BC',  'Employment',                       1, 'for_approval',        2, 'cash',  false),
      (35, 17, 'COI', 'Medical or financial assistance',  1, 'for_approval',        2, 'cash',  false),
      (36, 14, 'COR', 'Government ID application',        1, 'processing',          2, 'cash',  false),
      (37, 18, 'BBC', 'Business permit application',      1, 'processing',          1, 'maya',  false),
      (38, 20, 'BC',  'Travel',                           1, 'for_payment',         1, 'cash',  false),
      (39, 19, 'BC',  'Employment',                       1, 'for_payment',         1, 'cash',  false),
      (40, 22, 'COR', 'Scholarship',                      1, 'under_review',        1, 'cash',  false),
      (41, 21, 'COR', 'Government ID application',        1, 'pending',             0, 'cash',  false),
      (42, 16, 'COR', 'Employment',                       1, 'pending',             0, 'cash',  false),
      (43,  3, 'BC',  'Employment',                       1, 'pending',             0, 'cash',  false)
    ) as t(n, res, code, purpose, copies, final, days, method, waived)
    order by n
  loop
    select * into v_type from public.document_types where code = s.code;
    perform pg_temp.demo_act(v_sec);

    -- Filed at the counter
    v_t := pg_temp.demo_start(s.days);
    v_out := public.submit_request(
      v_res[s.res], v_type.id, (select id from public.purposes where name = s.purpose),
      null, s.copies,
      case when s.code <> 'BBC' then '{}' else case s.res
        when 6  then '{"business_name": "Navarro Sari-Sari Store", "business_nature": "retail of groceries", "business_address": "88 Bonifacio Street"}'
        when 11 then '{"business_name": "Valdez Eatery", "business_nature": "food service", "business_address": "60 Del Pilar Street"}'
        when 18 then '{"business_name": "Castro Water Refilling Station", "business_nature": "water refilling", "business_address": "73 Quezon Street"}'
        when 20 then '{"business_name": "Valencia Auto Repair", "business_nature": "vehicle repair", "business_address": "55 Aguinaldo Street"}'
      end end::jsonb,
      '[]'::jsonb, s.waived,
      case when s.waived then 'Senior citizen with no regular income; fee waived by the Secretary.' end);
    v_id := (v_out ->> 'id')::uuid;
    update public.document_requests set submitted_at = v_t, created_at = v_t where id = v_id;
    perform pg_temp.demo_stamp(v_t);
    continue when s.final = 'pending';

    v_t := pg_temp.demo_next(v_t);
    if s.final = 'cancelled' then
      perform public.transition_request(v_id, 'cancelled', 'The resident no longer needs the document.');
      perform pg_temp.demo_stamp(v_t);
      continue;
    end if;
    perform public.transition_request(v_id, 'under_review');
    perform pg_temp.demo_stamp(v_t);
    continue when s.final = 'under_review';

    v_t := pg_temp.demo_next(v_t);
    if s.final = 'rejected' then
      perform public.transition_request(v_id, 'rejected',
        'The name on the presented ID does not match the resident record.');
      perform pg_temp.demo_stamp(v_t);
      continue;
    end if;

    select fee_amount into v_fee from public.document_requests where id = v_id;
    if v_fee > 0 then
      perform public.transition_request(v_id, 'for_payment');
      perform pg_temp.demo_stamp(v_t);
      continue when s.final = 'for_payment';

      v_t := pg_temp.demo_next(v_t);
      loop
        v_or_txt := lpad(v_or::text, 7, '0');
        v_or := v_or + 1;
        exit when not exists (select 1 from public.payments where upper(or_number) = v_or_txt);
      end loop;
      perform pg_temp.demo_act(v_tre);
      v_pay := public.record_payment(v_id, v_or_txt, v_fee, s.method,
        case when s.method <> 'cash' then lpad(floor(random() * 1e13)::bigint::text, 13, '0') end);
      update public.payments set paid_at = v_t, created_at = v_t where id = v_pay;
      perform pg_temp.demo_stamp(v_t);
      perform pg_temp.demo_act(v_sec);
    else
      perform public.transition_request(v_id, 'processing');
      perform pg_temp.demo_stamp(v_t);
    end if;
    continue when s.final = 'processing';

    v_t := pg_temp.demo_next(v_t);
    if v_type.requires_captain_approval then
      perform public.transition_request(v_id, 'for_approval');
      perform pg_temp.demo_stamp(v_t);
      continue when s.final = 'for_approval';

      v_t := pg_temp.demo_next(v_t);
      perform pg_temp.demo_act(v_cap);
      if s.final = 'rejected_captain' then
        perform public.transition_request(v_id, 'rejected',
          'The stated purpose does not match the supporting details given.');
        perform pg_temp.demo_stamp(v_t);
        continue;
      end if;
    end if;

    v_out := public.issue_document(v_id);
    v_issued := (v_out ->> 'id')::uuid;
    update public.issued_documents
       set issued_at = v_t, created_at = v_t,
           valid_until = case when v_type.validity_days is not null
                              then (v_t at time zone 'Asia/Manila')::date + v_type.validity_days::integer end
     where id = v_issued;
    perform pg_temp.demo_stamp(v_t);
    perform pg_temp.demo_act(v_sec);
    continue when s.final = 'ready_for_release';

    v_t := pg_temp.demo_next(v_t);
    select concat_ws(' ', first_name, last_name) into v_name from public.residents where id = v_res[s.res];
    perform public.transition_request(v_id, 'released', null, v_name);
    update public.document_requests set released_at = v_t where id = v_id;
    perform pg_temp.demo_stamp(v_t);

    if s.final = 'revoked' then
      v_t := pg_temp.demo_next(v_t);
      perform public.revoke_document(v_issued,
        'Issued with a misspelled middle name; replaced by a corrected copy.');
      update public.issued_documents set revoked_at = v_t where id = v_issued;
      perform pg_temp.demo_stamp(v_t);
    end if;
  end loop;

  -- Move every request step into office hours ----------------------------------
  create temporary table demo_ids on commit drop as
    select d.id as request_id, p.id as payment_id, i.id as issued_id
    from public.document_requests d
    left join public.payments p on p.request_id = d.id
    left join public.issued_documents i on i.request_id = d.id
    where d.resident_id = any (v_res);

  update public.request_status_history set changed_at = pg_temp.demo_office(changed_at)
   where request_id in (select request_id from demo_ids);
  update public.document_requests
     set submitted_at = pg_temp.demo_office(submitted_at), created_at = pg_temp.demo_office(created_at),
         released_at = pg_temp.demo_office(released_at)
   where id in (select request_id from demo_ids);
  update public.payments set paid_at = pg_temp.demo_office(paid_at), created_at = pg_temp.demo_office(created_at)
   where id in (select payment_id from demo_ids);
  update public.issued_documents i
     set issued_at = pg_temp.demo_office(i.issued_at), created_at = pg_temp.demo_office(i.created_at),
         revoked_at = pg_temp.demo_office(i.revoked_at),
         valid_until = (pg_temp.demo_office(i.issued_at) at time zone 'Asia/Manila')::date + t.validity_days::integer
    from public.document_requests d
    join public.document_types t on t.id = d.document_type_id
   where i.request_id = d.id and i.id in (select issued_id from demo_ids);
  update public.notifications
     set created_at = pg_temp.demo_office(created_at),
         is_read = pg_temp.demo_office(created_at) < now() - interval '3 days'
   where request_id in (select request_id from demo_ids);
  update public.audit_logs set created_at = pg_temp.demo_office(created_at)
   where entity_id in (select request_id::text from demo_ids
                       union select payment_id::text from demo_ids where payment_id is not null
                       union select issued_id::text from demo_ids where issued_id is not null);

  raise notice 'Loaded % demo residents and 43 demo requests.', array_length(v_res, 1);
end
$demo$;

-- ---------------------------------------------------------------------
-- Remove the demo data: residents, their requests, payments, certificates
-- and notifications. Audit log entries stay, because that table is
-- append-only. Uncomment and run the whole block.
-- ---------------------------------------------------------------------
-- begin;
-- create temporary table demo_requests on commit drop as
--   select d.id from public.document_requests d
--   join public.residents r on r.id = d.resident_id
--   where r.email like '%@demo.e-dokumento.test';
-- delete from public.notifications where request_id in (select id from demo_requests);
-- delete from public.issued_documents where request_id in (select id from demo_requests);
-- delete from public.payments where request_id in (select id from demo_requests);
-- delete from public.document_requests where id in (select id from demo_requests);
-- delete from public.residents where email like '%@demo.e-dokumento.test';
-- commit;
