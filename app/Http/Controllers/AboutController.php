<?php

namespace App\Http\Controllers;

use App\Support\AboutInfo;
use Inertia\Inertia;
use Inertia\Response;

/** Public About page: no login needed, so anyone can find the licence and source. */
class AboutController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('About', ['about' => AboutInfo::forClient()]);
    }
}
