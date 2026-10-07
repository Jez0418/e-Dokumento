"""Fake Supabase (PostgREST + Auth) for the visual checks in tools/visual.

Serves made-up fixtures so every page can render without a real Supabase project. It ignores most
filters and never writes anything, so it is for looking at pages, not for testing business rules.
Sign in as <role>@example.test with any password (admin, captain, secretary, treasurer, resident).
"""
import os
import base64, json, time, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qsl

PORT = int(os.environ.get('FAKE_PORT', '8125'))


def uid(n):
    return '00000000-0000-4000-8000-%012d' % n


def iso(days_ago=0, hours_ago=0):
    t = time.time() - days_ago * 86400 - hours_ago * 3600
    return time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime(t))


def d(days_ago):
    return time.strftime('%Y-%m-%d', time.gmtime(time.time() - days_ago * 86400))


ROLES = {1: ('admin', 'Administrator'), 2: ('captain', 'Punong Barangay'), 3: ('secretary', 'Barangay Secretary'),
         4: ('treasurer', 'Barangay Treasurer'), 5: ('resident', 'Resident')}
USERS = {
    'admin': (uid(1), 1, 'Ramon Villanueva'), 'captain': (uid(2), 2, 'Eduardo Mercado'), 'secretary': (uid(3), 3, 'Maria Santos'),
    'treasurer': (uid(4), 4, 'Lorna Bautista'), 'resident': (uid(5), 5, 'Juan Dela Cruz'),
}


def profile(key):
    pid, rid, name = USERS[key]
    return {'id': pid, 'email': key + '@example.test', 'full_name': name, 'contact_no': '09171234567', 'status': 'active', 'role_id': rid,
            'last_login_at': iso(0, 2), 'created_at': iso(90), 'roles': {'code': ROLES[rid][0], 'name': ROLES[rid][1]}}


PUROKS = [{'id': i + 1, 'name': n, 'description': None, 'is_active': True} for i, n in enumerate(['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5'])]
PUROK = {p['id']: p for p in PUROKS}
PURPOSES = [{'id': i + 1, 'name': n, 'is_active': True} for i, n in enumerate(['Employment', 'Scholarship', 'Business permit', 'Medical assistance', 'Travel', 'School enrollment'])]
ID_TYPES = [{'id': i + 1, 'name': n, 'is_active': True} for i, n in enumerate(["Driver's license", 'PhilSys National ID', 'Voter ID', 'Passport'])]
REQS = [
    {'id': 1, 'name': 'Valid ID', 'description': 'A government-issued ID with your photo.', 'accepts_upload': True, 'is_active': True},
    {'id': 2, 'name': 'Proof of residency', 'description': 'Utility bill or lease contract.', 'accepts_upload': True, 'is_active': True},
    {'id': 3, 'name': 'Community tax certificate', 'description': None, 'accepts_upload': False, 'is_active': True},
    {'id': 4, 'name': 'Business permit application', 'description': 'Filled-out form from the municipal hall.', 'accepts_upload': True, 'is_active': True},
]
REQ = {r['id']: r for r in REQS}


def dtype(i, code, name, desc, fee, days, approval, tmpl, reqs, once=False):
    return {'id': i, 'code': code, 'name': name, 'description': desc, 'fee': fee, 'processing_days': days, 'validity_days': 180,
            'requires_captain_approval': approval, 'min_residency_months': 0, 'once_per_lifetime': once, 'max_copies': 3, 'template_key': tmpl,
            'is_active': True, 'document_type_requirements': [{'requirement_id': r, 'is_mandatory': m, 'sort_order': k, 'requirements': REQ[r]} for k, (r, m) in enumerate(reqs)]}


DTYPES = [
    dtype(1, 'BC', 'Barangay clearance', 'General-purpose clearance for employment and permits.', '50.00', 2, True, 'clearance', [(1, True), (2, True), (3, True)]),
    dtype(2, 'COR', 'Certificate of residency', 'Confirms where you live and since when.', '30.00', 1, False, 'residency', [(1, True), (2, True)]),
    dtype(3, 'COI', 'Certificate of indigency', 'For medical and scholarship assistance.', '0.00', 1, True, 'indigency', [(1, True)]),
    dtype(4, 'BBC', 'Business clearance', 'Required before a business permit is released.', '300.00', 3, True, 'business', [(1, True), (4, True)]),
    dtype(5, 'FTJ', 'First time jobseeker (RA 11261)', 'Free of charge, once per resident.', '0.00', 1, False, 'jobseeker', [(1, True)], True),
]
DTYPE = {t['id']: t for t in DTYPES}


def person(i, first, middle, last, purok, status='verified', birth=None, profile_id=None):
    return {'id': uid(100 + i), 'profile_id': profile_id, 'purok_id': purok, 'first_name': first, 'middle_name': middle, 'last_name': last, 'suffix': None,
            'birth_date': birth or d(365 * (20 + i * 3)), 'sex': 'male' if i % 2 else 'female', 'civil_status': 'single', 'street_address': '%d Mabini Street' % (10 + i),
            'contact_no': '0917%07d' % (1000000 + i), 'email': None, 'occupation': 'Driver', 'resident_since': d(365 * 8), 'is_registered_voter': i % 2 == 0,
            'verification_status': status, 'verified_by': None, 'verified_at': iso(20), 'status': 'active', 'created_at': iso(60), 'puroks': {'name': PUROK[purok]['name']}}


RESIDENTS = [
    person(1, 'Juan', 'Reyes', 'Dela Cruz', 1, profile_id=uid(5)),
    person(2, 'Ana', 'Lopez', 'Reyes', 2), person(3, 'Pedro', None, 'Garcia', 3), person(4, 'Liza', 'Cruz', 'Ramos', 1),
    person(5, 'Mark', None, 'Tan', 4), person(6, 'Rosa', 'Mae', 'Lim', 2), person(7, 'Carlo', None, 'Diaz', 5, status='pending'),
    person(8, 'Nina', 'Joy', 'Cruz', 3, status='unverified'), person(9, 'Elena', None, 'Bautista', 4, status='rejected'),
]
RES = {r['id']: r for r in RESIDENTS}


def full_name(r):
    return ' '.join(x for x in [r['first_name'], r['middle_name'], r['last_name']] if x)


STATUS_LIST = ['pending', 'under_review', 'for_payment', 'processing', 'for_approval', 'ready_for_release', 'released', 'rejected', 'cancelled']
OFFICIALS = [
    {'id': 1, 'full_name': 'Eduardo Mercado', 'position': 'punong_barangay', 'committee': None, 'term_start': d(700), 'term_end': d(-400), 'signature_path': None, 'is_active': True},
    {'id': 2, 'full_name': 'Maria Santos', 'position': 'secretary', 'committee': None, 'term_start': d(700), 'term_end': d(-400), 'signature_path': None, 'is_active': True},
    {'id': 3, 'full_name': 'Lorna Bautista', 'position': 'treasurer', 'committee': None, 'term_start': d(700), 'term_end': d(-400), 'signature_path': None, 'is_active': True},
    {'id': 4, 'full_name': 'Ricardo Flores', 'position': 'kagawad', 'committee': 'Peace and order', 'term_start': d(700), 'term_end': d(-400), 'signature_path': None, 'is_active': True},
    {'id': 5, 'full_name': 'Angela Navarro', 'position': 'sk_chairperson', 'committee': 'Youth', 'term_start': d(700), 'term_end': d(-400), 'signature_path': None, 'is_active': False},
]

REQUESTS = []
for i, st in enumerate(STATUS_LIST):
    res = RESIDENTS[0] if i in (0, 3, 5, 6, 7, 8) else RESIDENTS[i]
    t = DTYPES[[0, 1, 0, 3, 0, 2, 1, 2, 4][i]]
    rid = uid(300 + i)
    fee = t['fee'] if st != 'cancelled' else '0.00'
    ctrl = 'EDK-2026-%04d' % (481 - i)
    history = [{'id': 1, 'request_id': rid, 'from_status': None, 'to_status': 'pending', 'remarks': None, 'changed_at': iso(6 - min(i, 5), 2), 'profiles': {'full_name': res and 'Juan Dela Cruz'}}]
    order = ['pending', 'under_review', 'for_payment', 'processing', 'for_approval', 'ready_for_release', 'released']
    if st in order:
        for k, s2 in enumerate(order[1:order.index(st) + 1]):
            history.append({'id': k + 2, 'request_id': rid, 'from_status': order[k], 'to_status': s2, 'remarks': None, 'changed_at': iso(5 - k, 1), 'profiles': {'full_name': 'Maria Santos'}})
    elif st == 'rejected':
        history.append({'id': 2, 'request_id': rid, 'from_status': 'pending', 'to_status': 'under_review', 'remarks': None, 'changed_at': iso(3), 'profiles': {'full_name': 'Maria Santos'}})
        history.append({'id': 3, 'request_id': rid, 'from_status': 'under_review', 'to_status': 'rejected', 'remarks': 'Blurred ID photo.', 'changed_at': iso(2), 'profiles': {'full_name': 'Maria Santos'}})
    elif st == 'cancelled':
        history.append({'id': 2, 'request_id': rid, 'from_status': 'pending', 'to_status': 'cancelled', 'remarks': None, 'changed_at': iso(2), 'profiles': {'full_name': 'Juan Dela Cruz'}})
    pays = []
    if st in ('processing', 'for_approval', 'ready_for_release', 'released') and float(fee) > 0:
        pays = [{'id': uid(500 + i), 'request_id': rid, 'or_number': 'OR-%05d' % (1200 + i), 'amount': fee, 'method': 'cash', 'reference_no': None, 'status': 'posted',
                 'void_reason': None, 'paid_at': iso(3), 'received_by': USERS['treasurer'][0]}]
    issued = None
    if st in ('ready_for_release', 'released'):
        issued = {'id': uid(700 + i), 'request_id': rid, 'document_no': 'BC-2026-%05d' % (88 + i), 'verification_code': 'A1B2C3D4E%d' % i, 'signatory_id': 1, 'issued_at': iso(1), 'valid_until': d(-170), 'print_count': 1, 'status': 'valid'}
    atts = [{'id': uid(900 + i), 'request_id': rid, 'requirement_id': 1, 'file_path': 'x/id.jpg', 'original_name': 'valid-id.jpg', 'mime_type': 'image/jpeg', 'size_bytes': 412000,
             'review_status': 'accepted' if i > 1 else 'pending', 'review_remarks': None, 'uploaded_at': iso(5)}]
    REQUESTS.append({
        'id': rid, 'control_no': ctrl, 'resident_id': res['id'], 'document_type_id': t['id'], 'purpose_id': 1 + i % 5, 'purpose_details': None, 'copies': 1,
        'extra_details': {'business_name': 'Dela Cruz Sari-sari Store', 'business_address': '12 Mabini St', 'business_nature': 'Retail'} if t['template_key'] == 'business' else {},
        'channel': 'walk_in' if i == 3 else 'online', 'fee_amount': fee, 'fee_waived': False, 'waiver_reason': None, 'status': st,
        'rejection_reason': 'The ID photo is blurred. Upload a clear photo of the front.' if st == 'rejected' else None,
        'requested_by': USERS['resident'][0], 'submitted_at': iso(6 - min(i, 5), 2 + i), 'updated_at': iso(max(0, 4 - i), 1),
        'released_at': iso(0, 5) if st == 'released' else None, 'released_to': 'Juan Dela Cruz' if st == 'released' else None,
        'residents': res, 'document_types': t, 'purposes': {'name': PURPOSES[i % 5]['name']}, 'request_attachments': atts,
        'request_status_history': history, 'payments': pays, 'issued_documents': issued,
        # request_list view columns
        'resident_name': full_name(res), 'purok_name': res['puroks']['name'], 'document_type': t['name'], 'document_code': t['code'], 'purpose': PURPOSES[i % 5]['name'],
        'purok_id': res['purok_id'], 'processing_days': t['processing_days'], 'is_overdue': i == 4,
    })
REQ_BY_ID = {r['id']: r for r in REQUESTS}

PAYMENTS = []
for r in REQUESTS:
    for p in r['payments']:
        PAYMENTS.append(dict(p, control_no=r['control_no'], request_status=r['status'], document_type=r['document_type'], resident_name=r['resident_name'], received_by_name='Lorna Bautista'))
ISSUED = []
for r in REQUESTS:
    if r['issued_documents']:
        i = r['issued_documents']
        ISSUED.append(dict(i, barangay_officials=OFFICIALS[0], document_requests=r, control_no=r['control_no'], request_status=r['status'], document_type_id=r['document_type_id'], document_type=r['document_type'], resident_name=r['resident_name']))

SETTINGS = {'barangay_name': 'Isca', 'city_municipality': 'Candelaria', 'province': 'Quezon', 'office_hours': 'Mon to Fri, 8:00 AM to 5:00 PM',
            'hall_address': 'Purok 1, Barangay Isca', 'require_id_verification': 'on'}
SETTING_ROWS = [{'key': k, 'value': v, 'updated_at': iso(10), 'description': None} for k, v in SETTINGS.items()]

VERIFS = [
    {'id': uid(1100 + i), 'resident_id': r['id'], 'id_type_id': 1 + i % 4, 'id_number': 'N01-23-%06d' % (4500 + i), 'front_image_path': 'x/front.jpg', 'back_image_path': 'x/back.jpg' if i % 2 else None,
     'status': s, 'remarks': 'Name does not match the record.' if s == 'rejected' else None, 'submitted_at': iso(i), 'residents': r, 'id_types': ID_TYPES[i % 4]}
    for i, (r, s) in enumerate([(RESIDENTS[6], 'pending'), (RESIDENTS[7], 'pending'), (RESIDENTS[8], 'rejected')])
]
NOTIFS = [{'id': i + 1, 'recipient_id': USERS['resident'][0], 'request_id': REQUESTS[i]['id'], 'title': t, 'message': m, 'is_read': i > 1, 'created_at': iso(i)}
          for i, (t, m) in enumerate([('Ready to claim', 'Your Barangay clearance EDK-2026-0476 is ready for release.'), ('Payment received', 'We received your payment for EDK-2026-0478.'),
                                      ('Request under review', 'The Secretary is checking your files.')])]
AUDIT = [{'id': i + 1, 'actor_id': USERS['secretary'][0], 'action': a, 'entity_type': e, 'entity_id': uid(300 + i), 'details': {'status': 'processing'}, 'ip_address': '120.28.4.%d' % (10 + i),
          'user_agent': 'Mozilla/5.0', 'created_at': iso(0, i + 1), 'profiles': {'full_name': 'Maria Santos', 'email': 'secretary@example.test'}}
         for i, (a, e) in enumerate([('transition', 'document_request'), ('login', 'auth'), ('issue_document', 'issued_document'), ('record_payment', 'payment'), ('review_file', 'attachment')])]
PROFILES = [dict(profile(k)) for k in USERS]

TABLES = {
    'puroks': PUROKS, 'purposes': PURPOSES, 'id_types': ID_TYPES, 'requirements': REQS, 'document_types': DTYPES, 'residents': RESIDENTS,
    'request_list': REQUESTS, 'document_requests': REQUESTS, 'payment_list': PAYMENTS, 'payments': PAYMENTS, 'issued_document_list': ISSUED, 'issued_documents': ISSUED,
    'system_settings': SETTING_ROWS, 'resident_verifications': VERIFS, 'notifications': NOTIFS, 'audit_logs': AUDIT, 'barangay_officials': OFFICIALS,
    'profiles': PROFILES, 'roles': [{'id': k, 'code': v[0], 'name': v[1]} for k, v in ROLES.items()],
}


def summary():
    by = {}
    for r in REQUESTS:
        by[r['status']] = by.get(r['status'], 0) + 1
    cards = {k: 7 + i for i, k in enumerate(['pending', 'under_review', 'for_payment', 'processing', 'for_approval', 'ready_for_release'])}
    cards.update({'open': 51, 'overdue': 4, 'verifications_pending': 6, 'rejected_files': 2, 'residents_verified': 1284, 'released_month': 47, 'released_total': 3,
                  'collections_today': 1250, 'collections_month': 18340, 'voided_month': 1})
    return {'cards': cards, 'generated_at': iso(),
            'by_month': [{'label': m, 'submitted': a, 'released': b, 'rejected': c} for m, a, b, c in [('May', 42, 31, 3), ('Jun', 58, 44, 5), ('Jul', 51, 47, 2), ('Aug', 73, 60, 6), ('Sep', 66, 58, 4), ('Oct', 81, 52, 5)]],
            'by_type': [{'name': 'Barangay clearance', 'total': 34}, {'name': 'Certificate of residency', 'total': 22}, {'name': 'Indigency', 'total': 14}, {'name': 'Business clearance', 'total': 9}],
            'daily_collections': [{'label': 'Oct %d' % (i + 1), 'total': 300 + (i * 97) % 700} for i in range(30)]}


def jwt(key):
    b = lambda o: base64.urlsafe_b64encode(json.dumps(o).encode()).rstrip(b'=').decode()
    return b({'alg': 'none'}) + '.' + b({'sub': USERS[key][0], 'exp': int(time.time()) + 36000, 'role': 'authenticated'}) + '.sig'


def apply_filters(rows, params):
    out = rows
    for k, v in params:
        if k in ('select', 'order', 'limit', 'offset', 'or', 'and') or '.' not in v:
            continue
        op, _, val = v.partition('.')
        if op == 'eq':
            out = [r for r in out if k in r and str(r[k]).lower() == val.lower()]
        elif op == 'in' and k in (out[0] if out else {}):
            vals = val.strip('()').split(',')
            out = [r for r in out if str(r[k]) in vals]
        elif op == 'lt' and k in (out[0] if out else {}):
            out = [r for r in out if float(r[k]) < float(val)]
    return out


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, status, body, extra=None):
        data = json.dumps(body).encode() if body is not None else b''
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(data)

    def handle_any(self):
        u = urlparse(self.path)
        params = parse_qsl(u.query, keep_blank_values=True)
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length) if length else b''
        body = json.loads(raw) if raw and raw[:1] in b'{[' else {}
        p = u.path
        if p.startswith('/auth/v1/token'):
            email = (body.get('email') or '').split('@')[0]
            key = email if email in USERS else 'secretary'
            return self.send(200, {'access_token': jwt(key), 'refresh_token': 'rt-' + key, 'expires_in': 36000, 'token_type': 'bearer'})
        if p.startswith('/auth/v1/'):
            return self.send(200, {})
        if p.startswith('/rest/v1/rpc/'):
            fn = p.split('/')[-1]
            if fn == 'dashboard_summary':
                return self.send(200, summary())
            if fn == 'verify_document':
                return self.send(200, [{'status': 'valid'}])
            return self.send(200, [])
        if p.startswith('/rest/v1/'):
            table = p.split('/')[-1]
            if self.command in ('POST', 'PATCH', 'DELETE'):
                return self.send(200, [])
            rows = list(TABLES.get(table, []))
            rows = apply_filters(rows, params)
            total = len(rows)
            off = int(dict(params).get('offset', 0) or 0)
            lim = int(dict(params).get('limit', 1000) or 1000)
            rows = rows[off:off + lim]
            rng = '%d-%d/%d' % (off, max(off, off + len(rows) - 1), total) if rows else '*/%d' % total
            return self.send(200, rows, {'Content-Range': rng})
        return self.send(404, {'message': 'not found'})

    do_GET = do_POST = do_PATCH = do_DELETE = do_HEAD = do_PUT = handle_any


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
