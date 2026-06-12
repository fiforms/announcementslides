<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nearby sharing radius
    |--------------------------------------------------------------------------
    |
    | Default "as the crow flies" radius (in miles) used when a viewer enables
    | "include nearby" on the dashboard. Authenticated users can override this
    | with a per-user setting (setting_tag = 'nearby_radius_miles'); anonymous
    | viewers always use this default.
    |
    */
    'nearby_radius_miles' => env('SLIDES_NEARBY_RADIUS_MILES', 50),
];
