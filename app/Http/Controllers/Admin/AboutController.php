<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AboutInfo;
use Inertia\Inertia;
use Inertia\Response;

/** Admin > About: the public About content plus details of this installation. */
class AboutController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/About', [
            'about' => AboutInfo::forClient(),
            'system' => AboutInfo::systemDetails(),
        ]);
    }
}
