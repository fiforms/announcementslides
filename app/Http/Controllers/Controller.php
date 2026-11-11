<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 11 dropped this from the generated base controller, so
    // $this->authorize() is unavailable until it is put back. Policies are
    // the direction for this app's authorisation (see App\Policies), so it
    // belongs here rather than being repeated per controller.
    use AuthorizesRequests;
}
