<?php

declare(strict_types=1);

return [
    'max_size' => [
        'x' => 4000,
        'y' => 2000,
    ],
    'grid_size' => 20,

    'tracking' => [
        /*
         * Cache store holding the location continuity keys. It must be shared
         * by every worker (never a per-process store like array or octane).
         */
        'continuity_store' => env('TRACKING_CONTINUITY_STORE', 'redis'),

        /*
         * A change of system is only a jump when the previous position was
         * observed less than this many seconds earlier.
         */
        'continuity_seconds' => 45,
    ],
];
