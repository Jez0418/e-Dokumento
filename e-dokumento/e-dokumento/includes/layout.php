<?php
declare(strict_types=1);

/** Opens the signed-in application shell: head, sidebar, top bar, <main>. */
function layout_start(string $title, string $active = '', array $opts = []): void
{
    $user = Auth::user();
    $unread = 0;
    if ($user) {
        try {
            $unread = Supabase::user()->count('notifications', [['recipient_id', 'eq.' . $user['id']], ['is_read', 'eq.false']]);
        } catch (Throwable) {
            $unread = 0;
        }
    }
    $pageTitle = $title;
    $bodyClass = 'app';
    require BASE_PATH . '/includes/layout/header.php';
    echo '<div class="app-shell">';
    require BASE_PATH . '/includes/layout/sidebar.php';
    echo '<div class="app-body">';
    require BASE_PATH . '/includes/layout/navbar.php';
    echo '<main class="app-main" id="main" tabindex="-1">';
}

function layout_end(array $scripts = []): void
{
    echo '</main></div></div>';
    $pageScripts = $scripts;
    require BASE_PATH . '/includes/layout/footer.php';
}

/** Public pages (login, registration, verification) without the sidebar. */
function guest_start(string $title, string $bodyClass = 'guest'): void
{
    $pageTitle = $title;
    $user = null;
    require BASE_PATH . '/includes/layout/header.php';
}

function guest_end(array $scripts = []): void
{
    $pageScripts = $scripts;
    require BASE_PATH . '/includes/layout/footer.php';
}

/** Page heading row with an optional primary action. */
function page_header(string $title, string $lead = '', string $actionsHtml = ''): void
{
    echo '<header class="page-head"><div><h1>' . e($title) . '</h1>';
    if ($lead !== '') {
        echo '<p class="page-lead">' . e($lead) . '</p>';
    }
    echo '</div>';
    if ($actionsHtml !== '') {
        echo '<div class="page-actions">' . $actionsHtml . '</div>';
    }
    echo '</header>';
}
