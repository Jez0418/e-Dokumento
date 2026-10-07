<?php
declare(strict_types=1);

const STAFF_ROLES = ['admin', 'captain', 'secretary', 'treasurer'];
const RECORDS_ROLES = ['admin', 'captain', 'secretary'];

function has_role(string ...$roles): bool
{
    $role = Auth::role();
    return $role !== null && in_array($role, $roles, true);
}

function is_staff(): bool
{
    return has_role(...STAFF_ROLES);
}

/** Page and action guard: shows Access Denied instead of the page. */
function require_role(string ...$roles): void
{
    if (!has_role(...$roles)) {
        abort(403, 'Your role does not have access to this page.');
    }
}

/** Sidebar navigation, grouped by the work each role does. */
function nav_for_role(?string $role): array
{
    $overview = ['Overview', [
        ['dashboard', '/dashboard', 'grid-1x2', 'Dashboard'],
    ]];
    return match ($role) {
        'resident' => [
            $overview,
            ['My documents', [
                ['requests-new', '/requests/new', 'file-earmark-plus', 'Request a document'],
                ['requests', '/requests', 'files', 'My requests'],
            ]],
            ['Account', [
                ['profile', '/profile', 'person-vcard', 'Profile and verification'],
                ['notifications', '/notifications', 'bell', 'Notifications'],
            ]],
        ],
        'secretary' => [
            $overview,
            ['Counter', [
                ['requests', '/requests', 'files', 'Requests'],
                ['requests-new', '/requests/new', 'person-plus', 'Walk-in request'],
                ['verifications', '/verifications', 'person-check', 'Verifications'],
            ]],
            ['Records', [
                ['residents', '/residents', 'people', 'Residents'],
                ['documents', '/documents', 'patch-check', 'Issued documents'],
                ['reports', '/reports', 'bar-chart-line', 'Reports'],
            ]],
        ],
        'treasurer' => [
            $overview,
            ['Collections', [
                ['payments', '/payments', 'cash-coin', 'Cashiering'],
                ['requests', '/requests', 'files', 'Requests'],
                ['reports', '/reports', 'bar-chart-line', 'Reports'],
            ]],
        ],
        'captain' => [
            $overview,
            ['Approvals', [
                ['approvals', '/requests?status=for_approval', 'pen', 'For my approval'],
                ['requests', '/requests', 'files', 'All requests'],
            ]],
            ['Records', [
                ['residents', '/residents', 'people', 'Residents'],
                ['documents', '/documents', 'patch-check', 'Issued documents'],
                ['payments', '/payments', 'cash-coin', 'Payments'],
                ['reports', '/reports', 'bar-chart-line', 'Reports'],
                ['audit', '/audit', 'journal-text', 'Audit log'],
            ]],
        ],
        'admin' => [
            $overview,
            ['Records', [
                ['requests', '/requests', 'files', 'Requests'],
                ['residents', '/residents', 'people', 'Residents'],
                ['documents', '/documents', 'patch-check', 'Issued documents'],
                ['payments', '/payments', 'cash-coin', 'Payments'],
                ['reports', '/reports', 'bar-chart-line', 'Reports'],
            ]],
            ['Catalogue', [
                ['document-types', '/document-types', 'file-earmark-text', 'Document types'],
                ['requirements', '/requirements', 'list-check', 'Requirements'],
                ['reference', '/reference', 'tags', 'Puroks, purposes, IDs'],
                ['officials', '/officials', 'award', 'Officials'],
            ]],
            ['Administration', [
                ['users', '/users', 'person-gear', 'Users'],
                ['settings', '/settings', 'sliders', 'Settings'],
                ['audit', '/audit', 'journal-text', 'Audit log'],
            ]],
        ],
        default => [$overview],
    };
}
