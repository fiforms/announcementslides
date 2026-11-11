<?php

/*
|--------------------------------------------------------------------------
| First-run setup wizard
|--------------------------------------------------------------------------
|
| While the users table is empty the app offers /setup, which creates the
| first admin and (optionally) installs the bundled sample widgets. The moment
| any user exists the wizard is gone for good (404): there is no way to reopen
| it short of emptying the table.
|
*/
return [

    // If set, the setup form requires this value. Empty/unset = open, which is
    // convenient for a local test instance but means whoever reaches the page
    // first becomes the admin, so set it for anything reachable from outside.
    'token' => env('SETUP_TOKEN') ?: null,

    // While no user exists, send visitors of the home, login and register pages
    // to /setup. Set false to turn the redirect off (the page stays reachable).
    'redirect' => (bool) env('SETUP_REDIRECT', true),

    // Bundled widgets (folders under resources/widgets/) the wizard offers to
    // install. The clock, calendar and weather widgets are the useful ones;
    // `hello` and `examples` are author samples.
    'widgets' => ['clock', 'calendar', 'weather'],

];
