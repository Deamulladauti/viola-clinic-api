<?php

return [
  'paths' => ['api/*', 'sanctum/csrf-cookie'],
  'allowed_methods' => ['*'],
  'allowed_origins' => [
    'http://localhost:3000',   // CRA
    'http://localhost:5173',   // Vite
    'http://127.0.0.1:5173',
  ],
  'allowed_headers' => ['*'],
  'exposed_headers' => [],
  'max_age' => 0,
  'supports_credentials' => false, // PAT/Bearer tokens → keep false
];
