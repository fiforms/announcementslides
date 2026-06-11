<?php

namespace App\Http\Controllers;

use App\Models\Language;
use Inertia\Inertia;
use Inertia\Response;

class SubmitSlideController extends Controller
{
    public function index(): Response
    {
        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Submit/Index', [
            'languages' => $languages,
        ]);
    }
}
