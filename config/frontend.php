<?php

/*
|--------------------------------------------------------------------------
| First-Party Frontend Origins
|--------------------------------------------------------------------------
|
| Single source of truth for the SPA origins that are allowed to talk to
| this API. It is consumed by both config/cors.php (allowed_origins) and
| config/sanctum.php (stateful) via `require`, so the two configs cannot
| drift apart. Keeping them aligned is what prevents the
| "Session store not set on request." error: an origin that CORS allows
| but Sanctum does not treat as stateful would never get a session.
|
| Override with a comma-separated FRONTEND_ORIGINS env value if needed.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'FRONTEND_ORIGINS',
        'http://localhost:5173,http://34.150.126.247:8080,http://34.150.126.247'
    ))
)));

return [
    // Full origins (with scheme) for CORS allowed_origins.
    'origins' => $origins,

    // Sanctum stateful hosts are the same values without the scheme.
    'stateful' => array_values(array_filter(array_map(
        static fn (string $origin): string => preg_replace('#^https?://#', '', $origin),
        $origins
    ))),
];
