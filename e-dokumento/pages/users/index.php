<?php
declare(strict_types=1);

$db = Supabase::user();
$me = Auth::id();
$errors = [];
$old = $_POST;
$roles = $db->select('roles', [['select', 'id,code,name'], ['order', 'id.asc']])['rows'];
$roleByCode = [];
foreach ($roles as $r) {
    $roleByCode[$r['code']] = $r;
}
$staffCodes = ['admin', 'captain', 'secretary', 'treasurer'];
// Only one active account may hold the Punong Barangay role; the database enforces it too.
$captainId = $db->first('profiles', [['select', 'id'], ['role_id', 'eq.' . $roleByCode['captain']['id']], ['status', 'eq.active']])['id'] ?? null;
/** Role choices for one account: the captain role is offered only to the current holder, or to anyone while the seat is empty. */
$roleChoices = static function (?string $forUserId) use ($roles, $captainId): array {
    $map = pluck($roles, 'name', 'code');
    if ($captainId !== null && $captainId !== $forUserId) {
        unset($map['captain']);
    }
    return $map;
};

if (is_post()) {
    $action = post('action');
    $uid = post('user_id');
    try {
        if ($action === 'create') {
            $v = new Validator($_POST);
            $name = $v->text('full_name', 'Full name', true, 2, 120);
            $email = $v->email('email');
            $role = $v->in('role', 'role', $staffCodes);
            $password = $v->password('password', 'password_confirm', 'Temporary password');
            if (!$v->fails()) {
                // Creating accounts needs the Auth admin API (secret key, server only).
                // app_metadata.app_role is read by the handle_new_user trigger.
                Supabase::admin()->adminCreateUser((string) $email, (string) $password, ['full_name' => $name], ['app_role' => $role]);
                flash_success('Account created for ' . $name . '. Share the temporary password in person and ask them to change it.');
                redirect('/users');
            }
            $errors = $v->errors();
        } elseif ($action === 'update' && is_uuid($uid)) {
            if ($uid === $me) {
                throw new RuntimeException('You cannot change your own role or deactivate your own account.');
            }
            $role = post('role');
            if (!isset($roleByCode[$role])) {
                abort(400);
            }
            $to = post('status') === 'active' ? 'active' : 'inactive';
            $db->update('profiles', [['id', 'eq.' . $uid]], ['role_id' => (int) $roleByCode[$role]['id'], 'status' => $to]);
            try {
                // Also block (or unblock) sign-in at Supabase Auth itself
                Supabase::admin()->adminUpdateUser($uid, ['ban_duration' => $to === 'active' ? 'none' : '876000h']);
            } catch (Throwable $e) {
                error_log('ban: ' . $e->getMessage());
            }
            flash_success('Account saved.' . ($to === 'inactive' ? ' The user can no longer sign in.' : ''));
            redirect('/users');
        }
    } catch (SupabaseException $e) {
        $msg = strtolower($e->getMessage());
        $errors['_form'] = str_contains($msg, 'already') ? 'An account with that email already exists.' : db_error($e);
    } catch (RuntimeException $e) {
        $errors['_form'] = str_contains($e->getMessage(), 'SUPABASE_SECRET_KEY')
            ? 'Creating staff accounts needs SUPABASE_SECRET_KEY in the server environment. See the README.'
            : $e->getMessage();
    }
}

['page' => $page, 'per' => $per, 'offset' => $offset] = paging(20);
$f = [
    'q'      => search_term(q('q')),
    'role'   => isset($roleByCode[q('role')]) ? q('role') : '',
    'status' => in_array(q('status'), ['active', 'inactive'], true) ? q('status') : '',
];
$query = [['select', 'id,email,full_name,status,last_login_at,created_at,role_id,roles(code,name)']];
if ($f['q'] !== '') {
    $t = '"*' . $f['q'] . '*"';
    $query[] = ['or', "(full_name.ilike.{$t},email.ilike.{$t})"];
}
if ($f['role'] !== '') {
    $query[] = ['role_id', 'eq.' . $roleByCode[$f['role']]['id']];
}
if ($f['status'] !== '') {
    $query[] = ['status', 'eq.' . $f['status']];
}
$query[] = ['order', 'role_id.asc,full_name.asc'];
$result = $db->select('profiles', $query, true, $offset, $per);

$editUser = null;
if (is_uuid(q('edit')) && q('edit') !== $me) {
    $editUser = $db->first('profiles', [['select', 'id,email,full_name,status,roles(code,name)'], ['id', 'eq.' . q('edit')]]);
}

layout_start('Users', 'users');
page_header('Users', 'Staff accounts are created here. Residents create their own accounts by registering.');
?>
<?= form_errors(isset($errors['_form']) ? ['_form' => $errors['_form']] : []) ?>
<div class="row g-4">
  <div class="col-xl-8">
    <form class="filter-bar" method="get" role="search" data-live-filter>
      <div class="filter-search"><label class="visually-hidden" for="q">Search users</label><i class="bi bi-search" aria-hidden="true"></i><input id="q" name="q" type="search" class="form-control" value="<?= e($f['q']) ?>" placeholder="Name or email" maxlength="80"></div>
      <div><label class="form-label small" for="role">Role</label><select id="role" name="role" class="form-select"><?= options(pluck($roles, 'name', 'code'), $f['role'], 'Any') ?></select></div>
      <div><label class="form-label small" for="status">Status</label><select id="status" name="status" class="form-select"><?= options(['active' => 'Active', 'inactive' => 'Inactive'], $f['status'], 'Any') ?></select></div>
      <div class="filter-actions"><noscript><button class="btn btn-primary" type="submit">Apply</button></noscript><a class="btn btn-link" href="/users">Clear</a></div>
    </form>
    <section class="panel p-0">
      <?php if (!$result['rows']): ?><?= empty_state('No users match', 'Try another name or role.', '', '', 'person-gear') ?><?php else: ?>
      <p class="table-scroll-hint"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Swipe sideways to see every column.</p>
      <div class="table-responsive"><table class="table data-table">
        <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Last sign-in</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $u): $code = one($u['roles'])['code'] ?? 'resident'; $self = $u['id'] === $me; ?>
          <tr class="<?= $u['status'] === 'active' ? '' : 'row-muted' ?>">
            <td><strong><?= e($u['full_name']) ?></strong><?= $self ? ' <span class="tag tag-neutral">You</span>' : '' ?><small class="d-block text-secondary"><?= e($u['email']) ?></small></td>
            <td><?= e(one($u['roles'])['name'] ?? '') ?></td>
            <td><?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : 'Never') ?></td>
            <td><?= $u['status'] === 'active' ? simple_badge('Active', 'success') : simple_badge('Inactive', 'neutral') ?></td>
            <td class="text-end"><?php if (!$self): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('users', ['edit' => $u['id']])) ?>">Edit</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="panel-foot"><?= pagination($result['total'], $page, $per) ?></div>
      <?php endif; ?>
    </section>
  </div>
  <div class="col-xl-4">
    <?php if ($editUser): $euCode = one($editUser['roles'])['code'] ?? 'resident'; ?>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Edit account</h2><a href="/users" class="small">Cancel</a></div>
      <p class="mb-3"><strong><?= e($editUser['full_name']) ?></strong><small class="d-block text-secondary"><?= e($editUser['email']) ?></small></p>
      <form method="post" novalidate data-confirm="Save changes to this account? Role and status changes apply immediately.">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= e($editUser['id']) ?>">
        <div class="mb-3"><label class="form-label" for="edit-role">Role</label><select class="form-select" id="edit-role" name="role"><?= options($roleChoices($editUser['id']), $euCode) ?></select></div>
        <div class="mb-3"><label class="form-label" for="edit-status">Status</label><select class="form-select" id="edit-status" name="status"><?= options(['active' => 'Active', 'inactive' => 'Inactive'], $editUser['status']) ?></select><small class="d-block text-secondary">Inactive accounts are signed out and cannot sign in.</small></div>
        <button class="btn btn-primary w-100" type="submit">Save account</button>
      </form>
    </section>
    <?php endif; ?>
    <section class="panel">
      <div class="panel-head"><h2>Add a staff account</h2></div>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control<?= invalid($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= e(old($old, 'full_name')) ?>" required maxlength="120" placeholder="Juan D. Dela Cruz" aria-describedby="full_name-hint"><div class="form-text" id="full_name-hint">First name, middle initial, last name.</div><?= field_error($errors, 'full_name') ?></div>
        <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" type="email" value="<?= e(old($old, 'email')) ?>" required maxlength="254" placeholder="juan.delacruz@example.com" aria-describedby="email-hint"><div class="form-text" id="email-hint">A valid email the staff member can sign in with.</div><?= field_error($errors, 'email') ?></div>
        <div class="mb-3"><label class="form-label" for="new-role">Role</label><select class="form-select<?= invalid($errors, 'role') ?>" id="new-role" name="role" required><?= options(array_intersect_key($roleChoices(null), array_flip($staffCodes)), old($old, 'role'), 'Choose') ?></select><?= field_error($errors, 'role') ?></div>
        <div class="mb-3"><label class="form-label" for="password">Temporary password</label><input class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters" aria-describedby="password-hint"><div class="form-text" id="password-hint">At least 8 characters. The staff member should change it after signing in.</div><?= field_error($errors, 'password') ?></div>
        <div class="mb-3"><label class="form-label" for="password_confirm">Repeat password</label><input class="form-control<?= invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" type="password" required maxlength="72" autocomplete="new-password" data-match="password" placeholder="Retype the password above"><?= field_error($errors, 'password_confirm') ?><div class="invalid-feedback">The passwords do not match.</div></div>
        <button class="btn btn-primary w-100" type="submit" data-loading-text="Creating…">Create account</button>
      </form>
    </section>
  </div>
</div>
<?php layout_end(); ?>
