<?php
declare(strict_types=1);

if (!is_post()) {
    redirect(Auth::user() ? '/dashboard' : '/login');
}
Auth::logout();
flash_success('Signed out.');
redirect('/login');
