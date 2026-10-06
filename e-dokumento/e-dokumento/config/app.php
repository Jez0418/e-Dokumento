<?php
declare(strict_types=1);

return [
    'name'     => 'e-Dokumento',
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'secret'   => (string) Env::get('APP_SECRET', ''),
    'debug'    => Env::get('APP_DEBUG', 'false') === 'true',
    'timezone' => 'Asia/Manila',
    'per_page' => 15,
];
