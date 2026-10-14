-- The Officials list holds only the Punong Barangay.
begin;
select plan(3);

select throws_ok(
  $$insert into public.barangay_officials (full_name, position, term_start, term_end)
    values ('Some Kagawad', 'kagawad', date '2025-07-01', date '2028-06-30')$$,
  '23514', null, 'Other positions cannot be stored');

select lives_ok(
  $$insert into public.barangay_officials (full_name, position, term_start, term_end)
    values ('Test Punong Barangay', 'punong_barangay', date '2025-07-01', date '2028-06-30')$$,
  'The Punong Barangay can be stored');

select is((select count(*)::int from public.barangay_officials where position <> 'punong_barangay'),
  0, 'No other positions exist');

select * from finish();
rollback;
