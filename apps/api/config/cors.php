<?php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => explode(',', env('FRONTEND_URL', 'http://127.0.0.1:3000')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-CSRF-TOKEN', 'Idempotency-Key'],
    'exposed_headers' => [], 'max_age' => 0, 'supports_credentials' => true,
];
