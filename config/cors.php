<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    | Routes that should have CORS applied.
    | 'api/*' covers all your API routes.
    |
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    | HTTP methods allowed for CORS requests.
    | '*' allows all: GET, POST, PUT, PATCH, DELETE, OPTIONS
    |
    */
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    | Origins allowed to make cross-origin requests.
    |
    | Development : '*' (allow all)
    | Production  : ['https://yourapp.com', 'https://app.yourapp.com']
    |
    */
    'allowed_origins' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    | Regex patterns for allowed origins.
    | Leave empty if using allowed_origins above.
    |
    */
    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    | HTTP headers allowed in CORS requests.
    | '*' allows all headers including Authorization, Content-Type, etc.
    |
    */
    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    | Headers that browsers are allowed to access from the response.
    |
    */
    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    | How long (in seconds) browsers cache the CORS preflight response.
    | 0 = no caching
    |
    */
    'max_age' => 0,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    | Whether cookies/auth headers are included in CORS requests.
    | Set to true if using Sanctum cookie-based auth.
    | Must be false when allowed_origins is '*'.
    |
    */
    'supports_credentials' => false,

];