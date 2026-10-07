<?php
declare(strict_types=1);

/*
 * path => [page file, access]
 * access: 'public' (anyone), 'guest' (signed-out only), 'auth' (any signed-in role),
 *         or a list of role codes. Every page re-checks actions, and RLS checks again.
 */
$records = ['admin', 'captain', 'secretary'];
$staff = ['admin', 'captain', 'secretary', 'treasurer'];

return [
    '/'                     => ['pages/home.php', 'public'],
    '/login'                => ['pages/auth/login.php', 'guest'],
    '/register'             => ['pages/auth/register.php', 'guest'],
    '/forgot-password'      => ['pages/auth/forgot-password.php', 'guest'],
    '/reset-password'       => ['pages/auth/reset-password.php', 'public'],
    '/logout'               => ['pages/auth/logout.php', 'public'],
    '/verify'               => ['pages/public/verify.php', 'public'],

    '/dashboard'            => ['pages/dashboard/index.php', 'auth'],
    '/profile'              => ['pages/profile/index.php', 'auth'],
    '/notifications'        => ['pages/notifications/index.php', 'auth'],

    '/requests'             => ['pages/requests/index.php', 'auth'],
    '/requests/new'         => ['pages/requests/create.php', ['resident', 'secretary']],
    '/requests/view'        => ['pages/requests/view.php', 'auth'],
    '/requests/action'      => ['pages/requests/action.php', 'auth'],

    '/residents'            => ['pages/residents/index.php', $records],
    '/residents/form'       => ['pages/residents/form.php', ['secretary']],
    '/residents/view'       => ['pages/residents/view.php', $records],
    '/verifications'        => ['pages/verifications/index.php', ['secretary']],

    '/payments'             => ['pages/payments/index.php', $staff],
    '/payments/view'        => ['pages/payments/view.php', 'auth'],

    '/documents'            => ['pages/documents/index.php', $records],
    '/documents/print'      => ['pages/documents/print.php', 'auth'],

    '/document-types'       => ['pages/document-types/index.php', ['admin']],
    '/document-types/form'  => ['pages/document-types/form.php', ['admin']],
    '/requirements'         => ['pages/requirements/index.php', ['admin']],
    '/reference'            => ['pages/reference/index.php', ['admin']],
    '/officials'            => ['pages/officials/index.php', ['admin']],
    '/users'                => ['pages/users/index.php', ['admin']],
    '/settings'             => ['pages/settings/index.php', ['admin']],
    '/audit'                => ['pages/audit/index.php', ['admin', 'captain']],
    '/reports'              => ['pages/reports/index.php', $staff],
    '/reports/export'       => ['pages/reports/export.php', $staff],

    '/files'                => ['pages/files.php', 'auth'],
    '/api/dashboard'        => ['endpoints/dashboard.php', 'auth'],
    '/api/notifications'    => ['endpoints/notifications.php', 'auth'],
];
