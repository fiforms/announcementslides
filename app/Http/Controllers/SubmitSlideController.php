<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SubmitSlideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Submit/Index');
    }
}
