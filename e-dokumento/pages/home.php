<?php
declare(strict_types=1);

redirect(Auth::user() ? '/dashboard' : '/login');
