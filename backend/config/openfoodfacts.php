<?php

return [
    // https://openfoodfacts.github.io/openfoodfacts-server/api/
    'base_url' => env('OFF_BASE_URL', 'https://world.openfoodfacts.org'),

    // OFF requires a descriptive User-Agent: AppName/Version (contact email)
    'user_agent' => env('OFF_USER_AGENT', 'CulinaryPreferencesOrganizer/1.0 (lucaionescu1998@gmail.com)'),

    'timeout' => (int) env('OFF_TIMEOUT', 15),

    // Cache lifetime for OFF responses (seconds)
    'cache_ttl' => (int) env('OFF_CACHE_TTL', 3600),
];