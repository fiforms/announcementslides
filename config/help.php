<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Help content
    |--------------------------------------------------------------------------
    |
    | The in-app help is plain Markdown, one folder per language:
    | resources/help/<locale>/<topic>.md. See resources/help/README.md for the
    | front-matter fields and how to add a translation.
    |
    */
    'path' => resource_path('help'),

    // Topics missing from a language fall back to this one.
    'default_locale' => 'en',

    // Keep in step with supportedLocales in resources/js/i18n.js.
    'locales' => ['en', 'es'],
];
