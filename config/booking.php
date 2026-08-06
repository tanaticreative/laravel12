<?php
return [
    'rate_limits' => [
        'availability' => env('RATE_LIMIT_AVAILABILITY', '120,1'),
        'writes' => env('RATE_LIMIT_WRITES', '60,1'),
    ],

    'availability' => [
        'per_page' => (int) env('AVAILABILITY_PER_PAGE', 25),

        // A ceiling, not a suggestion: per_page is caller-controlled, and an
        // unbounded one lets a client ask for the whole table in one response.
        'max_per_page' => (int) env('AVAILABILITY_MAX_PER_PAGE', 100),
    ],
];
