<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Distance Between Store and User (in meters)
    |--------------------------------------------------------------------------
    |
    | We require check-in and check-out requests to originate from within this
    | radius of the configured store location. The value can be overridden
    | through the ATTENDANCE_MAX_DISTANCE_METERS environment variable.
    |
    */
    'max_distance_meters' => (int) env('ATTENDANCE_MAX_DISTANCE_METERS', 150),

    /*
    |--------------------------------------------------------------------------
    | Auto Check-out Threshold (in hours)
    |--------------------------------------------------------------------------
    |
    | When an employee forgets to check out, we will automatically check them
    | out after this many hours have elapsed since their recorded check-in time.
    | This can be overridden with ATTENDANCE_AUTO_CHECKOUT_AFTER_HOURS.
    |
    */
    'auto_checkout_after_hours' => (int) env('ATTENDANCE_AUTO_CHECKOUT_AFTER_HOURS', 4),
];
