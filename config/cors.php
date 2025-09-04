<?php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://nordhilfe-hamburg.de',
        'http://localhost:3000', // dev
        // add more if needed
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false, // set true only if you need cookies
];
